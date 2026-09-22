<?php

namespace Tests;

use PHPUnit\Framework\TestCase;

use AdminService\App;
use AdminService\Config;
use AdminService\Config\Repository;
use AdminService\Container;
use AdminService\Database\DatabaseConfig;
use AdminService\Error;
use AdminService\HttpRequest;
use AdminService\Log;
use base\Attribute\Config as ConfigAttribute;
use base\ConfigInterface;

use ReflectionProperty;

/**
 * 组件取配置的用例(`AdminService\core` 各组件按契约取配置)
 *
 * 三条主张:
 *  1. **注入即唯一来源**: 组件构造时拿到了契约实例, 就只认它 —— 门面后来怎么变都影响不到(快照语义)
 *  2. **未注入才回落门面**, 且**每次实取**(不缓存): 手工构造 / 测试的既有写法照旧可用
 *  3. **不给配置就别想静默拿到"空配置"**: 容器里没登记配置实例时, 构建吃配置的组件必须**当场报错**
 *
 * 外加两条:
 *  - 回归: 请求级容器在 `fork()` 时取"门面当前那份"配置 ——
 *    只靠父容器那份会让请求内的组件读到过期值
 *  - `.env` 的路径键覆盖经契约的 `get()` 自动作用于 `#[Config]` 注入(不必改调用点)
 */
class ComponentConfigTest extends TestCase {

    /**
     * 直接写门面里的私有静态仓储指针(用于精确还原 / 造"父容器那份过期"的局面)
     *
     * @param Repository|null $repository 仓储
     * @return void
     */
    private function setFacadeRepository(?Repository $repository): void {
        $property=new ReflectionProperty(Config::class,'repository');
        $property->setAccessible(true);
        $property->setValue(null,$repository);
    }

    /**
     * 测试: 注入的那份配置是**唯一来源**(门面里的同名键看不到)
     * @return void
     */
    public function testInjectedConfigIsTheOnlySource(): void {
        $repository=Config::repository();
        try {
            Config::set(array('database'=>array('connections'=>array('facade_only'=>array('type'=>'mysql')))));
            $scoped=new DatabaseConfig(new Repository(array('database'=>array('connections'=>array(
                'scoped'=>array('type'=>'mysql','dbname'=>'from-scoped'),
            )))));
            $this->assertSame('from-scoped',$scoped->connection('scoped')['dbname']);
            $this->assertSame(array(),$scoped->connection('facade_only'),'注入之后不得再读门面');
        } finally {
            $this->setFacadeRepository($repository);
        }
    }

    /**
     * 测试: 未注入时回落门面, 且**每次实取**(门面换新后立刻可见)
     * @return void
     */
    public function testFallbackReadsFacadeLive(): void {
        $repository=Config::repository();
        try {
            $config=new DatabaseConfig();
            Config::set(array('database'=>array('connections'=>array('a'=>array('type'=>'mysql')))));
            $this->assertSame('mysql',$config->connection('a')['type'],'第一次取值应看到新配置');
            Config::set(array('database'=>array('connections'=>array('b'=>array('type'=>'mysql')))));
            $this->assertSame('mysql',$config->connection('b')['type'],'再次换配置也应立刻可见(回落值不缓存)');
        } finally {
            $this->setFacadeRepository($repository);
        }
    }

    /**
     * 测试: `HttpRequest` 的参数来源顺序按注入的配置来
     * @return void
     */
    public function testHttpRequestUsesInjectedOrder(): void {
        $sources=array('query'=>array('n'=>'from-query'),'post'=>array('n'=>'from-post'));
        $get_first=new HttpRequest($sources,null,new Repository(array(
            'request'=>array('default'=>array('param'=>array('order'=>'GP'))),
        )));
        $post_first=new HttpRequest($sources,null,new Repository(array(
            'request'=>array('default'=>array('param'=>array('order'=>'PG'))),
        )));
        $this->assertSame('from-query',$get_first->param('n'));
        $this->assertSame('from-post',$post_first->param('n'));
    }

    /**
     * 测试: 容器里没登记配置实例时, 构建吃配置的组件要**当场报错**(不能静默给出空配置)
     *
     * - 机制: 组件的构造参数按类型 `base\ConfigInterface` 解析 → 找到一个实现类 `Repository` →
     *   而 `Repository` 的构造参数是**必填**的, 于是构建失败
     * - 这条是刻意的: 静默拿到"空配置"会让所有配置项都退回默认值, 是最难查的一类错
     *
     * @return void
     */
    public function testUnregisteredConfigFailsLoudly(): void {
        $container=new Container();
        // 失败点是"参数校验"(ArgumentResolver 发现 $configs 必填却拿不到值), 抛的是框架异常 —— 关键是**报错**而不是静默
        $this->expectException(\AdminService\Exception::class);
        $this->expectExceptionMessageMatches('/configs/');
        $container->make(Log::class);
    }

    /**
     * 测试: `Error::isDebug()` 按配置里的 `app.debug` 判定(错误 / 异常路径共用)
     * @return void
     */
    public function testErrorIsDebugFollowsConfig(): void {
        $repository=Config::repository();
        try {
            Config::set(array('app'=>array('debug'=>true)));
            $this->assertTrue(Error::isDebug());
            Config::set(array('app'=>array('debug'=>false)));
            $this->assertFalse(Error::isDebug());
            Config::set(array());
            $this->assertFalse(Error::isDebug(),'缺键时按非调试处理');
        } finally {
            $this->setFacadeRepository($repository);
        }
    }

    /**
     * 测试(回归): 请求级容器在 `fork()` 时取**门面当前那份**配置
     *
     * - 造法: 直接换门面指针(**绕过 `Config::set()` 的容器同步**), 于是父容器里那份就是"过期"的;
     *   此时跑一次请求, 请求内按契约取到的必须是新那份 —— 否则请求内的组件会读到过期配置
     *
     * @return void
     */
    public function testRequestForkTakesCurrentConfig(): void {
        $repository=Config::repository();
        $instance=App::hasInstance()?App::getInstance():null;
        try {
            // 先按配置装配好容器(请求级装配需要 `base\Request` 一类的别名), 再换门面指针
            App::init();
            $this->setFacadeRepository(new Repository(array('k'=>'fresh')));
            $seen=null;
            (new \AdminService\Application())->handle(function(Container $container) use (&$seen): void {
                $seen=$container->get(ConfigInterface::class)->get('k');
            });
            $this->assertSame('fresh',$seen,'请求容器必须取门面当前那份, 而不是父容器里过期的那份');
        } finally {
            $this->setFacadeRepository($repository);
            if($instance!==null)
                App::setInstance($instance);
        }
    }

    /**
     * 测试: `.env` 的路径键覆盖**也作用于 `#[Config]` 注入**(不必改任何调用点)
     *
     * - 注入走的是契约的 `get()`, 所以覆盖自动生效 —— 这条把"自动"钉住, 免得日后有人在
     *   参数解析器里另开一条读配置的路(那条路就享受不到覆盖了)
     *
     * @return void
     */
    public function testAttributeInjectionSeesEnvOverride(): void {
        $repository=new Repository(
            array('k'=>'from-file'),
            array(),
            new \AdminService\Config\Env('k=from-env')
        );
        $container=new Container();
        $container->instance(ConfigInterface::class,$repository);
        $this->assertSame('from-env',$container->exec_function(function(#[ConfigAttribute('k')] string $v=''): string {
            return $v;
        }));
    }

}
