<?php

namespace Tests;

use PHPUnit\Framework\TestCase;

use Tests\Fixtures\AbstractClass;
use Tests\Fixtures\ConcreteService;
use Tests\Fixtures\AbstractService;
use Tests\Fixtures\ServiceInterface;
use AdminService\App;
use AdminService\Config;
use AdminService\Exception;

use function get_class;

class AppTest extends TestCase {

    /**
     * 类初始化前执行
     * @return void
     */
    public static function setUpBeforeClass(): void {
        load_test_config();
    }

    /**
     * 测试基础绑定
     * @return void
     */
    public function testBind(): void {
        App::bind('Service', ConcreteService::class);
        // 绑定生效: 按别名取到的就是绑定的实现类(真实类名解析已收为容器内部实现)
        $this->assertInstanceOf(ConcreteService::class, App::get('Service'));
        $this->assertTrue(App::has('Service'));
    }

    /**
     * 测试自动寻找接口实现类
     * @return void
     */
    public function testAutoFindInterfaceImplementation(): void {
        $service=App::make(ServiceInterface::class);
        $this->assertInstanceOf(ConcreteService::class, $service);
        $service=App::get(ServiceInterface::class);
        $this->assertInstanceOf(ConcreteService::class, $service);
        $service=App::new(ServiceInterface::class);
        $this->assertInstanceOf(ConcreteService::class, $service);
    }

    /**
     * 测试实例化抽象类绑定的实现类
     * @return void
     */
    public function testAbstractBinding(): void {
        App::bind(AbstractService::class, ConcreteService::class);
        $service=App::make(AbstractService::class);
        $this->assertSame(ConcreteService::class, get_class($service));
        $service=App::get(AbstractService::class);
        $this->assertSame(ConcreteService::class, get_class($service));
        $service=App::new(AbstractService::class);
        $this->assertSame(ConcreteService::class, get_class($service));
    }

    /**
     * 测试不可实例化抽象类
     * @return void
     */
    public function testCannotInstantiateAbstractClass(): void {
        $this->expectException(Exception::class);
        $class=AbstractClass::class;
        $this->expectExceptionMessage("Class \"$class\" is not instantiable.");
        App::get($class);
    }

    /**
     * 测试不存在的类
     * @return void
     */
    public function testNonExistentClass(): void {
        $this->expectException(Exception::class);
        $class='ThisClassDoesNotExist';
        $this->expectExceptionMessage("Class \"$class\" not found.");
        App::get($class);
    }

}