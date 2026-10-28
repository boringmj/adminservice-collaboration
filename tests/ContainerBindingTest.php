<?php

namespace Tests;

use PHPUnit\Framework\TestCase;

use Tests\Fixtures\AbstractService;
use Tests\Fixtures\AbstractStatus;
use Tests\Fixtures\ConcreteService;
use Tests\Fixtures\ServiceInterface;
use Tests\Fixtures\UserInfo;
use Tests\Fixtures\UserStatus;
use AdminService\App;
use AdminService\Container;
use AdminService\Exception;
use AdminService\exception\AutowireException;
use base\Attribute\AutowireProperty;

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
        // UserInfo 的 status 属性按 AbstractStatus 注入, 明确给出实现, 避免依赖其他用例先加载 UserStatus
        App::bind(AbstractStatus::class,UserStatus::class);
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

    /**
     * 测试 get(): 未登记时做完整装配(构造参数也要注入)
     *
     * - 旧实现只 `newInstance()`(零参), 有必填构造依赖时会直接 ArgumentCountError
     *
     * @return void
     */
    public function testGetPerformsFullAssembly(): void {
        $consumer=App::getInstance()->get(RegressionConstructorConsumer::class);
        $this->assertInstanceOf(RegressionConstructorConsumer::class,$consumer);
        $this->assertInstanceOf(ConcreteService::class,$consumer->service);
    }

    /**
     * 测试抽象类依赖不会在构建栈里残留
     *
     * - 旧实现解析完抽象类后把抽象名留在 `$flags` 里, 同一类型的第二个属性会被误判成循环依赖
     *
     * @return void
     */
    public function testAbstractDependencyDoesNotLeakIntoBuildStack(): void {
        App::bind(AbstractStatus::class,UserStatus::class);
        $consumer=App::make(RegressionDoubleStatusConsumer::class);
        $this->assertInstanceOf(UserStatus::class,$consumer->first);
        $this->assertSame($consumer->first,$consumer->second,'同一抽象类型的第二个属性不应被误判为循环');
    }

    /**
     * 测试显式实现类优先于按类型绑定解析
     *
     * - 旧实现的 `is_a()` 参数方向写反, 显式实现类会被静默换成绑定里的实现
     *
     * @return void
     */
    public function testExplicitImplementationWinsOverBoundImplementation(): void {
        App::bind(RegressionExplicitInterface::class,RegressionBoundImplementation::class);
        $consumer=App::make(RegressionExplicitConsumer::class);
        $this->assertInstanceOf(RegressionAlternateService::class,$consumer->service,'显式指定的实现类不能被绑定解析结果顶掉');
    }

    /**
     * 测试显式实现类与声明类型不兼容时直接报错
     *
     * - 不能静默改注入另一个实现(那会让调用方以为注入的是自己指定的类)
     *
     * @return void
     */
    public function testExplicitIncompatibleClassIsRejected(): void {
        App::bind(RegressionExplicitInterface::class,RegressionBoundImplementation::class);
        $this->expectException(AutowireException::class);
        App::make(RegressionIncompatibleConsumer::class);
    }

    /**
     * 测试 has(): 有可实例化实现的接口也算"能给出"
     *
     * @return void
     */
    public function testHasInterfaceWithImplementation(): void {
        $container=App::getInstance();
        // 确保实现类已声明(ClassFinder 只在已声明类里查找)
        $this->assertTrue(class_exists(ConcreteService::class));
        $this->assertTrue($container->has(ServiceInterface::class));
        $this->assertInstanceOf(ServiceInterface::class,$container->get(ServiceInterface::class));
        $this->assertFalse($container->has(RegressionMissingInterface::class),'没有任何实现的接口仍然不可构建');
    }

    /**
     * 测试 fresh() 对无绑定接口同样强制新建实现类实例
     *
     * @return void
     */
    public function testFreshInterfaceBuildsNewImplementationInstance(): void {
        $container=App::getInstance();
        $old=$container->make(RegressionFreshImplementation::class);
        $first=$container->fresh(RegressionFreshInterface::class);
        $second=$container->fresh(RegressionFreshInterface::class);
        $this->assertInstanceOf(RegressionFreshImplementation::class,$first);
        $this->assertNotSame($old,$first);
        $this->assertNotSame($first,$second);
    }

    /**
     * 测试 fresh() 对无绑定抽象类同样强制新建实现类实例
     *
     * @return void
     */
    public function testFreshAbstractBuildsNewImplementationInstance(): void {
        $container=App::getInstance();
        $old=$container->make(RegressionFreshConcrete::class);
        $fresh=$container->fresh(RegressionFreshAbstract::class);
        $this->assertInstanceOf(RegressionFreshConcrete::class,$fresh);
        $this->assertNotSame($old,$fresh);
    }

}

/**
 * 构造依赖消费者
 */
final class RegressionConstructorConsumer {
    public function __construct(public ConcreteService $service) {
    }
}

/**
 * 同一抽象类型的两个属性(用于固定构建栈不能残留抽象名)
 */
final class RegressionDoubleStatusConsumer {
    #[AutowireProperty(AbstractStatus::class)]
    public AbstractStatus $first;

    #[AutowireProperty(AbstractStatus::class)]
    public AbstractStatus $second;
}

/**
 * 只用于显式类名用例的接口(避免干扰全局 ClassFinder 对 ServiceInterface 的既有断言)
 */
interface RegressionExplicitInterface {
}

/**
 * 绑定表里的默认实现
 */
final class RegressionBoundImplementation implements RegressionExplicitInterface {
}

/**
 * 显式指定的第二个实现
 */
final class RegressionAlternateService implements RegressionExplicitInterface {
}

/**
 * 显式指定第二个实现
 */
final class RegressionExplicitConsumer {
    #[AutowireProperty(RegressionAlternateService::class)]
    public RegressionExplicitInterface $service;
}

/**
 * 显式指定一个与声明类型无关的类
 */
final class RegressionIncompatibleConsumer {
    #[AutowireProperty(UserStatus::class)]
    public RegressionExplicitInterface $service;
}

/**
 * 没有任何实现类的接口
 */
interface RegressionMissingInterface {
}

/**
 * fresh() 用例接口
 */
interface RegressionFreshInterface {
}

/**
 * fresh() 用例实现类
 */
final class RegressionFreshImplementation implements RegressionFreshInterface {
}

/**
 * fresh() 抽象类用例
 */
abstract class RegressionFreshAbstract {
}

/**
 * fresh() 抽象类实现
 */
final class RegressionFreshConcrete extends RegressionFreshAbstract {
}
