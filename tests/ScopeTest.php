<?php

namespace Tests;

use PHPUnit\Framework\TestCase;

use base\Container as ContainerContract;
use base\Request;
use base\RouteContextInterface;
use Tests\Fixtures\AbstractService;
use Tests\Fixtures\ConcreteService;
use Tests\Fixtures\ServiceInterface;
use AdminService\App;
use AdminService\Application;
use AdminService\Container;
use AdminService\RouteContext;

/**
 * 生命周期分层(应用级 / 请求级 scope)用例
 *
 * - `fork()`:共享绑定 / 别名 / 单例与反射缓存, 实例表与全局数据独立
 * - `Application::handle()`:每次请求一个干净容器; 上一个请求的容器在下一次请求开始时被回收
 * - `reset()`:清实例与数据, 保留绑定
 */
class ScopeTest extends TestCase {

    /**
     * 类初始化前执行
     * @return void
     */
    public static function setUpBeforeClass(): void {
        load_test_config();
    }

    /**
     * 每个用例一个干净容器(并按配置装配, 与框架引导一致: 请求/响应等靠配置别名解析)
     * @return void
     */
    protected function setUp(): void {
        App::setInstance(new Container());
        App::init();
    }

    /**
     * 测试 fork:共享绑定, 父容器实例可见, 子容器登记不回流
     * @return void
     */
    public function testForkSharesBindingsAndIsolatesInstances(): void {
        $app=App::getInstance();
        $app->bind(ServiceInterface::class, ConcreteService::class);
        $shared=new ConcreteService();
        $app->instance(ServiceInterface::class, $shared);

        $child=$app->fork();
        // 绑定(别名/单例表同理)对子容器可见
        $this->assertTrue($child->has(ServiceInterface::class));
        // 父容器登记的实例对子容器可见, 且是同一个对象
        $this->assertSame($shared, $child->get(ServiceInterface::class));
        // 子容器登记的实例不回流到父容器
        $child_only=new ConcreteService();
        $child->instance(ConcreteService::class, $child_only);
        $this->assertNotSame($child_only, $app->get(ConcreteService::class));
    }

    /**
     * 测试 fork:请求级对象(路由上下文)不回流向父容器, 父容器的对子容器可见
     * @return void
     */
    public function testRouteContextIsRequestScoped(): void {
        $app=App::getInstance();
        $app->instance(RouteContextInterface::class,new RouteContext('demo','Index','index'));
        $child=$app->fork();
        // 父容器登记的请求级对象对子容器可见
        $this->assertTrue($child->hasInstance(RouteContextInterface::class));
        // 子容器登记的实例不回流
        $child->instance(RouteContextInterface::class,new RouteContext('index','Home','index'));
        $this->assertSame('demo',$app->get(RouteContextInterface::class)->appName());
        $this->assertSame('index',$child->get(RouteContextInterface::class)->appName());
    }

    /**
     * 测试 fork:参数转换开关跟随父容器(属进程级配置)
     * @return void
     */
    public function testForkFollowsParamCast(): void {
        $app=App::getInstance();
        $app->setParamCast(false);
        $this->assertFalse($app->fork()->getParamCast());
    }

    /**
     * 测试连续两次请求互不串味(含请求级容器被回收)
     * @return void
     */
    public function testRequestsDoNotLeakBetweenHandles(): void {
        $application=new Application();
        $containers=array();
        $marker=new ConcreteService();
        $application->handle(function() use (&$containers,$marker): void {
            $containers[]=App::getInstance();
            $containers[0]->instance(ConcreteService::class,$marker);
            $this->assertTrue($containers[0]->hasInstance(ConcreteService::class));
        });
        $application->handle(function() use (&$containers): void {
            $containers[]=App::getInstance();
            // 第二次请求:新容器, 看不到上一次请求登记的实例
            $this->assertNotSame($containers[0],$containers[1]);
            $this->assertFalse($containers[1]->hasInstance(ConcreteService::class));
            // 请求级对象也是新的
            $this->assertNotSame($containers[0]->get(Request::class),$containers[1]->get(Request::class));
        });
        // 上一个请求的容器已被回收(reset)
        $this->assertFalse($containers[0]->hasInstance(ConcreteService::class));
    }

    /**
     * 测试请求结束后门面指针收回应用级容器
     * @return void
     */
    public function testPointerReturnsToApplicationContainer(): void {
        $application=new Application();
        $app_container=$application->container();
        $application->handle(function() use ($app_container): void {
            $this->assertNotSame($app_container,App::getInstance());
        });
        $this->assertSame($app_container,App::getInstance());
        // 响应出口在关停阶段经 Application 取请求级对象
        $this->assertNotSame($app_container,$application->requestContainer());
    }

    /**
     * 测试 reset:清实例与数据, 保留绑定, 自身登记仍在
     * @return void
     */
    public function testResetKeepsBindings(): void {
        $container=new Container();
        $container->bind(ServiceInterface::class, ConcreteService::class);
        $first=$container->get(ServiceInterface::class);
        $container->instance(ConcreteService::class,new ConcreteService());
        $container->reset();
        // 实例表清空
        $this->assertFalse($container->hasInstance(ConcreteService::class));
        // 重新解析得到新实例
        $this->assertNotSame($first,$container->get(ServiceInterface::class));
        // 绑定保留
        $this->assertTrue($container->has(ServiceInterface::class));
        // 容器自身仍可按契约取到
        $this->assertSame($container,$container->get(ContainerContract::class));
    }

}
