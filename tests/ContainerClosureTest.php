<?php

namespace Tests;

use PHPUnit\Framework\TestCase;

use AdminService\App;
use AdminService\Container;
use AdminService\Exception;
use AdminService\exception\CircularDependencyException;
use Tests\Fixtures\ConcreteService;
use base\Container as ContainerContract;

/**
 * 容器闭包工厂测试
 *
 * - `bind()` 为瞬态工厂, `singleton()` 为单例工厂
 * - 覆盖构造注入、别名、fork、reset、异常与非法返回值
 */
class ContainerClosureTest extends TestCase {

    /**
     * 初始化前执行
     * @return void
     */
    public static function setUpBeforeClass(): void {
        load_test_config();
    }

    /**
     * 每个用例使用独立容器
     * @return void
     */
    protected function setUp(): void {
        App::setInstance(new Container());
    }

    /**
     * App 门面转发闭包绑定
     * @return void
     */
    public function testAppFacadeClosureBinding(): void {
        App::bind('app.factory',function(): object {
            return new ConcreteService();
        });
        $this->assertSame(App::get('app.factory'),App::get('app.factory'));
        App::factory('app.transient',function(): object {
            return new ConcreteService();
        });
        $this->assertNotSame(App::get('app.transient'),App::get('app.transient'));
        App::singleton('app.singleton',function(): object {
            return new ConcreteService();
        });
        $this->assertSame(App::get('app.singleton'),App::get('app.singleton'));
    }

    /**
     * bind() 闭包工厂首次解析后缓存
     * @return void
     */
    public function testBindClosureCaches(): void {
        $container=new Container();
        $count=0;
        $container->bind(ClosureServiceContract::class,function(ContainerContract $container) use (&$count): object {
            $count++;
            return new ClosureService();
        });
        $this->assertTrue($container->has(ClosureServiceContract::class));
        $this->assertFalse($container->hasInstance(ClosureServiceContract::class));
        $this->assertSame(
            $container->get(ClosureServiceContract::class),
            $container->make(ClosureServiceContract::class)
        );
        $this->assertSame(1,$count);
        $this->assertTrue($container->hasInstance(ClosureServiceContract::class));
    }

    /**
     * factory() 闭包工厂每次解析都调用
     * @return void
     */
    public function testFactoryClosureIsTransient(): void {
        $container=new Container();
        $count=0;
        $container->factory(ClosureServiceContract::class,function() use (&$count): object {
            $count++;
            return new ClosureService();
        });
        $this->assertTrue($container->has(ClosureServiceContract::class));
        $this->assertFalse($container->hasInstance(ClosureServiceContract::class));
        $this->assertNotSame(
            $container->get(ClosureServiceContract::class),
            $container->make(ClosureServiceContract::class)
        );
        $this->assertSame(2,$count);
        $this->assertFalse($container->hasInstance(ClosureServiceContract::class),'瞬态工厂不应写实例表');
    }

    /**
     * singleton() 闭包工厂缓存实例, fresh() 重新调用
     * @return void
     */
    public function testSingletonClosureCachesAndFreshReplaces(): void {
        $container=new Container();
        $count=0;
        $container->singleton(ClosureServiceContract::class,function() use (&$count): object {
            $count++;
            return new ClosureService();
        });
        $first=$container->get(ClosureServiceContract::class);
        $second=$container->make(ClosureServiceContract::class);
        $this->assertSame($first,$second);
        $this->assertSame(1,$count);
        $this->assertTrue($container->hasInstance(ClosureServiceContract::class));
        $fresh=$container->fresh(ClosureServiceContract::class);
        $this->assertNotSame($first,$fresh);
        $this->assertSame(2,$count);
        $this->assertSame($fresh,$container->get(ClosureServiceContract::class));
    }

    /**
     * 闭包工厂可取得容器并参与构造注入
     * @return void
     */
    public function testClosureReceivesContainerAndSupportsInjection(): void {
        $container=new Container();
        $dependency=new ConcreteService();
        $container->instance(ConcreteService::class,$dependency);
        $container->singleton(ClosureServiceContract::class,function(ContainerContract $container): ClosureServiceContract {
            return new ClosureDependencyConsumer($container->get(ConcreteService::class));
        });
        $consumer=$container->make(ClosureFactoryConsumer::class);
        $this->assertInstanceOf(ClosureDependencyConsumer::class,$consumer->service);
        $this->assertSame($dependency,$consumer->service->dependency);
        $this->assertSame($consumer->service,$container->get(ClosureServiceContract::class));
    }

    /**
     * 已登记实例优先于闭包工厂
     * @return void
     */
    public function testExplicitInstanceOverridesFactory(): void {
        $container=new Container();
        $count=0;
        $container->bind('factory.service',function() use (&$count): object {
            $count++;
            return new ConcreteService();
        });
        $explicit=new ConcreteService();
        $container->instance('factory.service',$explicit);
        $this->assertSame($explicit,$container->get('factory.service'));
        $this->assertSame(0,$count);
    }

    /**
     * 别名可以指向闭包工厂
     * @return void
     */
    public function testAliasToClosureFactory(): void {
        $container=new Container();
        $count=0;
        $container->bind('factory.service',function() use (&$count): object {
            $count++;
            return new ConcreteService();
        });
        $container->alias('service.alias','factory.service');
        $this->assertInstanceOf(ConcreteService::class,$container->get('service.alias'));
        $this->assertSame(1,$count);
    }

    /**
     * fork() 复制工厂但隔离实例表
     * @return void
     */
    public function testForkCopiesFactoriesButIsolatesInstances(): void {
        $container=new Container();
        $count=0;
        $container->singleton(ClosureServiceContract::class,function() use (&$count): object {
            $count++;
            return new ClosureService();
        });
        $child=$container->fork();
        $child_service=$child->get(ClosureServiceContract::class);
        $parent_service=$container->get(ClosureServiceContract::class);
        $this->assertNotSame($parent_service,$child_service);
        $this->assertSame(2,$count);
        $this->assertSame($parent_service,$container->get(ClosureServiceContract::class));
        $this->assertSame($child_service,$child->get(ClosureServiceContract::class));
    }

    /**
     * reset() 保留工厂绑定并清空单例实例
     * @return void
     */
    public function testResetKeepsFactoryBinding(): void {
        $container=new Container();
        $count=0;
        $container->singleton(ClosureServiceContract::class,function() use (&$count): object {
            $count++;
            return new ClosureService();
        });
        $first=$container->get(ClosureServiceContract::class);
        $container->reset();
        $this->assertFalse($container->hasInstance(ClosureServiceContract::class));
        $this->assertTrue($container->has(ClosureServiceContract::class));
        $second=$container->get(ClosureServiceContract::class);
        $this->assertNotSame($first,$second);
        $this->assertSame(2,$count);
    }

    /**
     * 闭包工厂返回非对象时拒绝
     * @return void
     */
    public function testClosureMustReturnObject(): void {
        $container=new Container();
        $container->bind('factory.invalid',function(): string {
            return 'invalid';
        });
        $this->expectException(Exception::class);
        $container->get('factory.invalid');
    }

    /**
     * 类 / 接口绑定的工厂返回值必须匹配类型
     * @return void
     */
    public function testClosureMustMatchAbstractType(): void {
        $container=new Container();
        $container->bind(ClosureServiceContract::class,function(): object {
            return new \stdClass();
        });
        $this->expectException(Exception::class);
        $container->get(ClosureServiceContract::class);
    }

    /**
     * 工厂内部解析自身时拒绝循环
     * @return void
     */
    public function testClosureCircularDependencyRejected(): void {
        $container=new Container();
        $container->bind('factory.loop',function(ContainerContract $container): object {
            return $container->get('factory.loop');
        });
        $this->expectException(CircularDependencyException::class);
        $container->get('factory.loop');
    }

    /**
     * build() 不支持闭包工厂
     * @return void
     */
    public function testBuildRejectsClosureFactory(): void {
        $container=new Container();
        $container->bind('factory.service',function(): object {
            return new ConcreteService();
        });
        $this->expectException(Exception::class);
        $container->build('factory.service');
    }

}

/**
 * 闭包工厂测试用接口
 */
interface ClosureServiceContract {
}

/**
 * 闭包工厂返回的简单实现
 */
final class ClosureService implements ClosureServiceContract {
}

/**
 * 闭包工厂返回的服务
 */
final class ClosureDependencyConsumer implements ClosureServiceContract {

    /**
     * @param ConcreteService $dependency 依赖
     */
    public function __construct(public ConcreteService $dependency) {
    }

    /**
     * @return string
     */
    public function execute(): string {
        return 'closure';
    }

}

/**
 * 需要接口依赖的消费者
 */
final class ClosureFactoryConsumer {

    /**
     * @param ClosureServiceContract $service 服务
     */
    public function __construct(public ClosureServiceContract $service) {
    }

}
