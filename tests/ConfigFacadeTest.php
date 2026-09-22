<?php

namespace Tests;

use PHPUnit\Framework\TestCase;

use AdminService\App;
use AdminService\Config;
use AdminService\Config\Repository;
use AdminService\Container;
use base\Attribute\Config as ConfigAttribute;
use base\ConfigInterface;

use ReflectionProperty;

/**
 * 配置门面用例(`AdminService\Config`)
 *
 * 门面自身是**全局状态**, 所以这里每条用例都要把"门面指针 / 容器指针"按原样还回去
 * (见 `withRestoredGlobals()`), 否则会污染后面整个进程的用例。
 *
 * 覆盖三条主张:
 *  1. 未装配时 `get/all/has` **安全回落到默认值**, 不再抛"未初始化静态属性"(旧版 A1)
 *  2. `new Config($configs)` **不可用**(私有构造): 旧版那个写法会顺手改全局, 语义意外(旧版 B4)
 *  3. 配置实例是**唯一的一份**: 门面与容器指向同一对象; `set()` 之后两边同步换新,
 *     且按 `base\ConfigInterface` 注入拿到的就是它 —— 不再是"转发到全局静态的替身"(旧版 B2)
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
     * 测试: 未装配时门面安全回落(旧版 A1: 未初始化的静态属性会直接抛 Error)
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
     * 测试: 门面不可实例化(旧版 `new Config([...])` 会改全局配置)
     * @return void
     */
    public function testFacadeIsNotInstantiable(): void {
        $this->expectException(\Error::class);
        new Config(array('a'=>1));
    }

    /**
     * 测试: `set()` 整体替换并立即可读(签名与旧版一致)
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
            // 若内核偷偷读全局门面, 这一步就会变成 'from-facade'(旧版 B3 正是如此)
            Config::set(array('k'=>'from-facade'));
            $this->assertSame('dflt',$container->exec_function($probe),'内核不得改读全局门面');
            $container->instance(ConfigInterface::class,new Repository(array('k'=>'v')));
            $this->assertSame('v',$container->exec_function($probe),'登记后按契约取到配置');
        });
    }

    /**
     * 测试: 按契约注入拿到的是**同一份**配置实例(旧版是转发到全局静态的替身 = 假解耦)
     * @return void
     */
    public function testContractInjectionReturnsTheSameInstance(): void {
        $repository=new Repository(array('x'=>1));
        $container=new Container();
        $container->instance(ConfigInterface::class,$repository);
        $this->assertSame($repository,$container->get(ConfigInterface::class));
        $this->assertSame(1,$container->get(ConfigInterface::class)->get('x'));
    }

}
