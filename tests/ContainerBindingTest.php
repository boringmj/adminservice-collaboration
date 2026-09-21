<?php

namespace Tests;

use PHPUnit\Framework\TestCase;

use Tests\Fixtures\AbstractService;
use Tests\Fixtures\ConcreteService;
use Tests\Fixtures\ServiceInterface;
use Tests\Fixtures\UserInfo;
use AdminService\App;
use AdminService\Container;
use AdminService\Exception;

/**
 * 容器绑定与解析语义用例
 *
 * - 覆盖 S5 补全的绑定 API:instance / bind / alias / singleton / bindAll / has / make / fresh / build
 * - 覆盖解析语义:`get()`/`make()` 复用实例、`fresh()` 每次新建、`build()` 带参且不登记
 */
class ContainerBindingTest extends TestCase {

    /**
     * 类初始化前执行
     * @return void
     */
    public static function setUpBeforeClass(): void {
        load_test_config();
    }

    /**
     * 每个用例用一个干净的容器(实例状态, 互不污染)
     * @return void
     */
    protected function setUp(): void {
        App::setInstance(new Container());
    }

    /**
     * 测试 bind:抽象名绑定实现类, 取用与复用
     * @return void
     */
    public function testBindAndReuse(): void {
        App::bind(ServiceInterface::class, ConcreteService::class);
        $this->assertTrue(App::has(ServiceInterface::class));
        $this->assertInstanceOf(ConcreteService::class, App::get(ServiceInterface::class));
        // 复用:同一实现只构建一次
        $this->assertSame(App::make(ServiceInterface::class), App::make(ServiceInterface::class));
    }

    /**
     * 测试 instance:登记现成对象, 并在别名/契约名下都能取到
     * @return void
     */
    public function testInstanceRegistration(): void {
        $service=new ConcreteService();
        App::instance(ServiceInterface::class, $service);
        $this->assertSame($service, App::get(ServiceInterface::class));
    }

    /**
     * 测试 alias:别名与绑定分表, 支持链式解析
     * @return void
     */
    public function testAliasChain(): void {
        App::bind(AbstractService::class, ConcreteService::class);
        App::alias('service', 'service.alias');
        App::alias('service.alias', AbstractService::class);
        $this->assertTrue(App::has('service'));
        $this->assertInstanceOf(ConcreteService::class, App::get('service'));
    }

    /**
     * 测试 singleton:抽象名与实现类名指向同一实例
     * @return void
     */
    public function testSingletonSharesInstance(): void {
        App::singleton(ServiceInterface::class, ConcreteService::class);
        $by_abstract=App::get(ServiceInterface::class);
        $by_class=App::get(ConcreteService::class);
        $this->assertSame($by_abstract, $by_class);
    }

    /**
     * 测试 make 与 fresh 的差别(复用 vs 每次新建)
     * @return void
     */
    public function testMakeReusesFreshDoesNot(): void {
        App::bind(AbstractService::class, ConcreteService::class);
        $this->assertSame(App::make(AbstractService::class), App::make(AbstractService::class));
        $this->assertNotSame(App::fresh(AbstractService::class), App::fresh(AbstractService::class));
    }

    /**
     * 测试 build:带构造参数、做完整装配、但不登记
     * @return void
     */
    public function testBuildWithArgsAndAutowire(): void {
        // build 会做属性装配(#[AutowireProperty]), 与 make 的装配一致
        $user=App::build(UserInfo::class);
        $this->assertInstanceOf(UserInfo::class, $user);
        $this->assertNotNull($user->name);
        // 不登记:build 出来的对象不会成为后续 get/build 的复用对象
        $this->assertNotSame($user, App::build(UserInfo::class));
    }

    /**
     * 测试 bindAll:批量绑定
     * @return void
     */
    public function testBindAll(): void {
        App::getInstance()->bindAll(array(
            ServiceInterface::class=>ConcreteService::class,
            AbstractService::class=>ConcreteService::class,
        ));
        $this->assertInstanceOf(ConcreteService::class, App::get(ServiceInterface::class));
        $this->assertInstanceOf(ConcreteService::class, App::get(AbstractService::class));
    }

    /**
     * 测试 has:未登记但可构建的类同样为真(容器能给出它)
     * @return void
     */
    public function testHasForBuildableClass(): void {
        $this->assertTrue(App::has(ConcreteService::class));
        $this->assertFalse(App::has('ThisClassDoesNotExist'));
    }

    /**
     * 测试循环校验:自绑定与别名成环都在注册期报错
     * @return void
     */
    public function testCircularBindingRejected(): void {
        $this->expectException(Exception::class);
        App::bind(ConcreteService::class, ConcreteService::class);
    }

    /**
     * 测试别名成环在注册期报错
     * @return void
     */
    public function testCircularAliasRejected(): void {
        App::alias('a', 'b');
        $this->expectException(Exception::class);
        App::alias('b', 'a');
    }

}
