<?php

namespace Tests;

use PHPUnit\Framework\TestCase;

use AdminService\App;
use AdminService\Router\Router;
use base\RoutingInterface;
use Tests\Fixtures\RoutingController;

/**
 * 控制器路由契约用例
 *
 * - 路由实现以 `base\RoutingInterface` 暴露给控制器, 避免控制器依赖实现层 Router
 * - 控制器基类 `url()` 通过容器里的路由契约生成 URL
 */
class ControllerRoutingTest extends TestCase {

    /**
     * 类初始化前执行
     * @return void
     */
    public static function setUpBeforeClass(): void {
        load_test_config();
        App::init();
    }

    /**
     * 测试 Router 实现路由契约
     * @return void
     */
    public function testRouterImplementsRoutingContract(): void {
        $router=new Router();
        $router->get('/index/{name?}',array(\app\index\controller\Index::class,'index'))->name('test.index');
        $this->assertInstanceOf(RoutingInterface::class,$router);
        $this->assertTrue($router->has('test.index'));
        $this->assertFalse($router->has('missing.index'));
        $this->assertSame('/index/world',$router->url('test.index',array('name'=>'world')));
    }

    /**
     * 测试控制器通过容器中的路由契约生成 URL
     * @return void
     */
    public function testControllerUrlUsesRoutingContract(): void {
        $router=new Router();
        $router->get('/index/{name?}',array(\app\index\controller\Index::class,'index'))->name('test.index');
        App::instance(RoutingInterface::class,$router);
        /** @var RoutingController $controller */
        $controller=App::make(RoutingController::class);
        $this->assertSame('/index/world',$controller->link('world'));
    }

}
