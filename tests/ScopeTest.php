<?php

namespace Tests;

use PHPUnit\Framework\TestCase;

use base\Container as ContainerContract;
use base\Request;
use Tests\Fixtures\AbstractService;
use Tests\Fixtures\ConcreteService;
use Tests\Fixtures\ServiceInterface;
use AdminService\App;
use AdminService\Application;
use AdminService\Container;

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
     * 测试 fork:全局数据不共享(请求级数据不出请求)
     * @return void
     */
    public function testForkDataIsIsolated(): void {
        $app=App::getInstance();
        $app->setData('from_app','1');
        $child=$app->fork();
        $this->assertNull($child->getData('from_app'));
        $child->setData('from_child','2');
        $this->assertNull($app->getData('from_child'));
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
        $application->handle(function() use (&$containers): void {
            $containers[]=App::getInstance();
            $containers[0]->setData('marker','first');
            $this->assertSame('first',App::getInstance()->getData('marker'));
        });
        $application->handle(function() use (&$containers): void {
            $containers[]=App::getInstance();
            // 第二次请求:新容器, 看不到上一次请求的数据
            $this->assertNotSame($containers[0],$containers[1]);
            $this->assertNull(App::getInstance()->getData('marker'));
            // 请求级对象也是新的
            $this->assertNotSame($containers[0]->get(Request::class),$containers[1]->get(Request::class));
        });
        // 上一个请求的容器已被回收
        $this->assertNull($containers[0]->getData('marker'));
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
        $container->setData('k','v');
        $container->reset();
        // 数据清空
        $this->assertNull($container->getData('k'));
        // 实例表清空(重新解析得到新实例)
        $this->assertNotSame($first,$container->get(ServiceInterface::class));
        // 绑定保留
        $this->assertTrue($container->has(ServiceInterface::class));
        // 容器自身仍可按契约取到
        $this->assertSame($container,$container->get(ContainerContract::class));
    }

}
