<?php

namespace Tests;

use PHPUnit\Framework\TestCase;

use AdminService\App;
use AdminService\Config;
use AdminService\Config\Repository;
use AdminService\Container;
use AdminService\exception\ConfigException;
use base\Attribute\Config as ConfigAttribute;
use base\ConfigInterface;

use ReflectionProperty;

use function dirname;
use function is_array;

/**
 * 配置门面用例(`AdminService\Config`)
 *
 * 门面自身是**全局状态**, 所以这里每条用例都要把"门面指针 / 容器指针"按原样还回去
 * (见 `withRestoredGlobals()`), 否则会污染后面整个进程的用例。
 *
 * 覆盖三条主张:
 *  1. 未装配时 `get/all/has` **安全回落到默认值**(不会抛"未初始化静态属性")
 *  2. `new Config($configs)` **不可用**(私有构造): 那个写法会顺手改掉全局配置, 语义意外
 *  3. 配置实例是**唯一的一份**: 门面与容器指向同一对象; `set()` 之后两边同步换新,
 *     且按 `base\ConfigInterface` 注入拿到的就是它(不是"转发到全局静态的替身")
 */
class ConfigFacadeTest extends TestCase {

    /**
     * 保存并还原门面指针与容器指针
     *
     * - 直接改私有静态而不是用 `Config::set()` 还原: 要连**实例本身**一起还回去, 不只是内容
     *
     * @param callable $fn 用例主体
     * @return void
     */
    private function withRestoredGlobals(callable $fn): void {
        $repository=Config::repository();
        $instance=App::hasInstance()?App::getInstance():null;
        try {
            $fn();
        } finally {
            $this->setFacadeRepository($repository);
            if($instance!==null)
                App::setInstance($instance);
        }
    }

    /**
     * 直接写门面里的私有静态仓储指针(仅测试用: 用来构造"未装配"态与精确还原)
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
     * 测试: 未装配时门面安全回落(未初始化的静态属性会直接抛 Error)
     * @return void
     */
    public function testUnloadedFacadeIsSafe(): void {
        $this->withRestoredGlobals(function(): void {
            $this->setFacadeRepository(null);
            $this->assertNull(Config::repository());
            $this->assertFalse(Config::hasRepository());
            $this->assertSame(array(),Config::all());
            $this->assertSame('dflt',Config::get('app.debug','dflt'),'未装配必须回落默认值 —— 引导期会走到这里');
            $this->assertFalse(Config::has('app.debug'));
        });
        $this->assertNotNull(Config::repository(),'还原后门面应当仍有仓储');
    }

    /**
     * 测试: 门面不可实例化(`new Config([...])` 那个写法会改全局配置)
     * @return void
     */
    public function testFacadeIsNotInstantiable(): void {
        $this->expectException(\Error::class);
        new Config(array('a'=>1));
    }

    /**
     * 测试: `set()` 整体替换并立即可读
     * @return void
     */
    public function testSetInstallsRepository(): void {
        $this->withRestoredGlobals(function(): void {
            Config::set(array('a'=>array('b'=>1),'c'=>null));
            $this->assertTrue(Config::hasRepository());
            $this->assertSame(1,Config::get('a.b'));
            $this->assertSame('dflt',Config::get('c','dflt'),'null 视为不存在(与 get 口径一致)');
            $this->assertFalse(Config::has('c'));
            $this->assertSame(array('a'=>array('b'=>1),'c'=>null),Config::all());
        });
    }

    /**
     * 测试: `set()` 之后**容器里那一份也要换新**(否则出现"门面新、容器旧"的最难查的不一致)
     * @return void
     */
    public function testSetKeepsContainerInSync(): void {
        $this->withRestoredGlobals(function(): void {
            $container=new Container();
            App::setInstance($container);
            Config::set(array('k'=>'v1'));
            $first=$container->get(ConfigInterface::class);
            $this->assertSame('v1',$first->get('k'));
            Config::set(array('k'=>'v2'));
            $second=$container->get(ConfigInterface::class);
            $this->assertNotSame($first,$second,'容器必须换成新实例');
            $this->assertSame('v2',$second->get('k'));
            $this->assertSame('v2',Config::get('k'));
        });
    }

    /**
     * 测试: `setRepository()` 收下**给定**的那份实例(门面不自己造)
     *
     * - 反证口径: 设置之前门面里是别的仓储, 设置之后必须**就是**传进去那个对象(不是"内容相同的新对象"),
     *   否则"引导方装配、门面只装"这条分工就落不了地 —— 引用不同会让已注入的组件继续用旧配置
     *
     * @return void
     */
    public function testSetRepositoryTakesTheGivenRepository(): void {
        $this->withRestoredGlobals(function(): void {
            Config::set(array('k'=>'from-set'));
            $assembled=new Repository(array('k'=>'from-assembled'));
            Config::setRepository($assembled);
            $this->assertSame($assembled,Config::repository(),'设进去的必须是传进去那个实例本身');
            $this->assertSame('from-assembled',Config::get('k'));
        });
    }

    /**
     * 测试: `setRepository()` 之后**容器里那两条登记都要换新**(契约名 + 实现类名)
     *
     * - 引导期 `Application::init()` 与请求期 `handle()` 都是**契约名与实现类名各登记一条**;
     *   若这里只换契约名那条, 两条就会指向不同的配置(契约名 → 新的, 实现类名 → 旧的)
     *
     * @return void
     */
    public function testSetRepositoryKeepsContainerInSync(): void {
        $this->withRestoredGlobals(function(): void {
            $container=new Container();
            App::setInstance($container);
            $stale=new Repository(array('k'=>'stale'));
            $container->instance(ConfigInterface::class,$stale);
            $container->instance(Repository::class,$stale);
            Config::setRepository(new Repository(array('k'=>'v1')));
            $first=$container->get(ConfigInterface::class);
            $this->assertSame('v1',$first->get('k'));
            $this->assertSame($first,$container->get(Repository::class),'两个名字必须指向同一个实例');
            Config::setRepository(new Repository(array('k'=>'v2')));
            $second=$container->get(ConfigInterface::class);
            $this->assertNotSame($first,$second,'容器必须换成新实例');
            $this->assertSame('v2',$second->get('k'));
            $this->assertSame($second,$container->get(Repository::class),'实现类名那条也要换成新实例');
        });
    }

    /**
     * 测试: `Application` 收下**传进去**的配置, 不去门面摸
     *
     * - 反证口径: 门面里放一份不同的配置, 构造时显式传入的必须胜出
     *
     * @return void
     */
    public function testApplicationPrefersInjectedConfig(): void {
        $this->withRestoredGlobals(function(): void {
            Config::set(array('k'=>'from-facade'));
            $injected=new Repository(array('k'=>'from-injected'));
            $application=new \AdminService\Application(null,$injected);
            $this->assertSame($injected,$application->config());
            $this->assertSame('from-injected',$application->config()->get('k'));
        });
    }

    /**
     * 测试: `Application::init()` 之后,**门面里就是应用实际在用的那一份**
     *
     * - 反证口径: 门面里先摆一份不同的, 引导完必须换成应用手里那份 —— 否则"容器里是新配置、
     *   门面里是旧的"就是可表示的状态, 那类不一致最难查
     * - 用**新容器**做场地: 引导会往容器里登记配置, 不能让这份改动留在共享的测试容器里
     *
     * @return void
     */
    public function testApplicationInitMakesItsConfigCurrent(): void {
        $this->withRestoredGlobals(function(): void {
            $container=new Container();
            App::setInstance($container);
            Config::set(array('k'=>'from-facade'));
            $injected=new Repository(array('k'=>'from-injected'));
            (new \AdminService\Application($container,$injected))->init();
            $this->assertSame($injected,Config::repository(),'门面里必须是应用手里那份');
            $this->assertSame($injected,$container->get(ConfigInterface::class),'容器里那份也是同一个实例');
        });
    }

    /**
     * 测试: `env()` 读的是**当前配置那一层 `.env`** —— 与 `get()` 的生效值是两条通道
     *
     * - 三件事: ①有快照时给原值(不折算、不受路径键覆盖影响) ②没带快照的仓储该层视为空
     *   ③未设置仓储时同样回落默认值(与 `get()` / `file()` 一致)
     * - 用**本地快照**构造, 免得断言依赖本机 `.env` 的内容(口令之类不该进断言)
     *
     * @return void
     */
    public function testEnvReadsTheCurrentConfigDotEnvLayer(): void {
        $this->withRestoredGlobals(function(): void {
            Config::setRepository(new Repository(array('k'=>'v'),array(),new \AdminService\Config\Env("RAW=raw-value\nMISSING=")));
            $this->assertSame('raw-value',Config::env('RAW'),'有快照: 给 `.env` 原值');
            $this->assertSame('',Config::env('MISSING'),'键在但值为空串, 照样给空串(不回落默认值)');
            $this->assertSame('dflt',Config::env('NOT_IN_ENV_AT_ALL','dflt'),'缺失即回落默认值');
            $this->assertNull(Config::env('NOT_IN_ENV_AT_ALL'),'不传默认值就是 null, 不抛');
            // 同一个键: 生效值被路径键覆盖并折算, `.env` 那层给原值
            Config::setRepository(new Repository(
                array('database'=>array('connections'=>array('default'=>array('port'=>3306)))),
                array(),
                new \AdminService\Config\Env('database.connections.default.port=3307')
            ));
            $this->assertSame(3307,Config::get('database.connections.default.port'),'生效值: 被路径键覆盖并折算成 int');
            $this->assertSame('3307',Config::env('database.connections.default.port'),'`.env` 层: 原始字符串(既不折算, 也不体现"覆盖的结果")');
            // 没带快照的仓储: 该层视为空(与 get/all 在无快照时不套覆盖同一口径)
            Config::setRepository(new Repository(array('k'=>'v')));
            $this->assertSame('dflt',Config::env('RAW','dflt'),'没快照: 回落默认值');
            // 未设置仓储: 同样回落默认值
            $this->setFacadeRepository(null);
            $this->assertSame('dflt',Config::env('RAW','dflt'),'未设置仓储: 回落默认值(与 get/file 一致)');
            $this->assertFalse(Config::has('k'),'对照: 同一状态下配置项不可见(那是另一条通道)');
        });
    }

    /**
     * 测试: 容器内核的取值回调读的是"容器里登记的实例", 没登记时回落默认值
     *
     * - 登记前: 一个裸容器不该凭空拿到配置(更不能静默拿到"空配置")
     * - 登记后: `#[Config]` 形参注入立刻生效
     *
     * @return void
     */
    public function testContainerResolverUsesRegisteredInstance(): void {
        $this->withRestoredGlobals(function(): void {
            $container=new Container();
            $probe=fn(#[ConfigAttribute('k')] string $value='dflt'): string => $value;
            // 反证口径: 门面里**有** k, 但容器没登记 —— 必须仍是默认值。
            // 若内核偷偷读全局门面, 这一步就会变成 'from-facade'
            Config::set(array('k'=>'from-facade'));
            $this->assertSame('dflt',$container->exec_function($probe),'内核不得改读全局门面');
            $container->instance(ConfigInterface::class,new Repository(array('k'=>'v')));
            $this->assertSame('v',$container->exec_function($probe),'登记后按契约取到配置');
        });
    }

    /**
     * 测试: 按契约注入拿到的是**同一份**配置实例(而不是"转发到全局静态的替身")
     * @return void
     */
    public function testContractInjectionReturnsTheSameInstance(): void {
        $repository=new Repository(array('x'=>1));
        $container=new Container();
        $container->instance(ConfigInterface::class,$repository);
        $this->assertSame($repository,$container->get(ConfigInterface::class));
        $this->assertSame(1,$container->get(ConfigInterface::class)->get('x'));
    }


    /**
     * 测试: `setValue()` 的临时值优先级最高(盖过 `.env` 与配置文件), 且不牵动其它层
     * @return void
     */
    public function testSetValueWinsOverEverything(): void {
        $this->withRestoredGlobals(function(): void {
            Config::set(array('log'=>array('path'=>'from-set')));
            Config::setValue('log.path','from-runtime');
            $this->assertSame('from-runtime',Config::get('log.path'));
            $this->assertSame('from-set',Config::file('log.path'),'`file()` 反映的是 set 进去的那棵树');
            try {
                Config::setValue('nope.nope',1);
                $this->fail('配置项不存在时应当抛 ConfigException');
            } catch(ConfigException $e) {
                $this->assertStringContainsString('nope.nope',$e->getMessage());
            }
        });
    }

}
