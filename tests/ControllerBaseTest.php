<?php

namespace Tests;

use PHPUnit\Framework\TestCase;

use Tests\Fixtures\ConfigNamedController;
use AdminService\App;
use AdminService\Config;
use base\ConfigInterface;
use ReflectionProperty;

/**
 * 控制器基类用例
 *
 * - 覆盖"子类属性与基类内部管道同名"的兼容性(基类用 private 才能互不冲突)
 * - 覆盖 `#[Config]` 注入到控制器子类的私有属性
 */
class ControllerBaseTest extends TestCase {

    /**
     * 类初始化前执行
     * @return void
     */
    public static function setUpBeforeClass(): void {
        load_test_config();
        // 按配置装配容器: 控制器构造依赖 base\Request 等契约别名
        App::init();
    }

    /**
     * 测试同名属性不冲突: 子类能正常构建
     *
     * - 基类的 `$config` / `$container` 若改回 `protected`, 本用例会以致命错误失败
     *
     * @return void
     */
    public function testControllerWithSameNamedPropertiesCanBeBuilt(): void {
        /** @var ConfigNamedController $controller */
        $controller=App::make(ConfigNamedController::class);
        $this->assertInstanceOf(ConfigNamedController::class,$controller);
        // 子类自己的属性保持自己的值
        $this->assertSame('own-container',$controller->ownContainer());
        // 子类的 `$config` 收到注入的配置项值(而不是基类的配置契约对象)
        $this->assertSame(Config::get('data.ext_name'),$controller->ownConfig());
    }

    /**
     * 测试基类内部管道未被覆盖(仍是配置契约对象)
     * @return void
     */
    public function testBasePipelinePropertyIsIntact(): void {
        $controller=App::make(ConfigNamedController::class);
        $property=new ReflectionProperty(\base\Controller::class,'config');
        $property->setAccessible(true);
        $this->assertInstanceOf(ConfigInterface::class,$property->getValue($controller));
    }

}
