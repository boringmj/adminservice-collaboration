<?php

namespace Tests;

use PHPUnit\Framework\TestCase;

use AdminService\App;
use AdminService\Container;
use AdminService\DynamicProxy;
use AdminService\exception\AutowireException;
use base\Attribute\Config as ConfigAttribute;
use base\Attribute\AutowireLifecycle;
use base\Attribute\AutowireMethod;
use base\Attribute\AutowireMode;
use base\Attribute\AutowireProperty;
use base\Attribute\AutowireSetter;

/**
 * 自动注入模式与注解拆分用例
 *
 * - `AutowireMode::Deferred`: 根对象登记后统一刷新, 可消解属性循环依赖
 * - `AutowireMode::Proxy`: 严格校验声明类型, 无参代理复用容器实例
 * - `AutowireLifecycle` 与单依赖 `AutowireMethod` 的职责分离
 * - 旧 `bool` / 无 `name` 写法的过渡兼容
 */
class AutowireModesTest extends TestCase {

    /**
     * 类初始化前执行
     * @return void
     */
    public static function setUpBeforeClass(): void {
        load_test_config();
    }

    /**
     * 延迟属性 / Setter / 方法均在根对象登记后执行
     * @return void
     */
    public function testDeferredPropertySetterAndMethod(): void {
        $property_dependency=App::make(DeferredPropertyDependency::class);
        $property_consumer=App::make(DeferredPropertyConsumer::class);
        $this->assertSame($property_dependency,$property_consumer->dependency);

        $setter_dependency=App::make(DeferredSetterDependency::class);
        $setter_consumer=App::make(DeferredSetterConsumer::class);
        $this->assertTrue($setter_consumer->called);
        $this->assertSame($setter_dependency,$setter_consumer->dependency);

        $method_dependency=App::make(DeferredMethodDependency::class);
        $method_consumer=App::make(DeferredMethodConsumer::class);
        $this->assertTrue($method_consumer->called);
        $this->assertSame($method_dependency,$method_consumer->dependency);
    }

    /**
     * Deferred 队列随容器隔离, 子容器 fork() 后不共享待执行任务
     *
     * @return void
     */
    public function testDeferredQueueIsolatedAcrossFork(): void {
        $parent=new Container();
        $child=$parent->fork();

        $child_consumer=$child->get(DeferredPropertyConsumer::class);
        $this->assertInstanceOf(DeferredPropertyDependency::class,$child_consumer->dependency);
        $this->assertTrue($child->hasInstance(DeferredPropertyConsumer::class));
        $this->assertFalse($parent->hasInstance(DeferredPropertyConsumer::class),'子容器的延迟任务不应写入父容器');
        $this->assertFalse($parent->hasInstance(DeferredPropertyDependency::class),'子容器的延迟任务不应写入父容器');

        $parent_consumer=$parent->get(DeferredPropertyConsumer::class);
        $this->assertInstanceOf(DeferredPropertyDependency::class,$parent_consumer->dependency);
        $this->assertNotSame($child_consumer,$parent_consumer);
        $this->assertNotSame($child_consumer->dependency,$parent_consumer->dependency);
    }

    /**
     * 延迟任务按登记后的根对象解析, 因此循环依赖两侧都是完整对象
     * @return void
     */
    public function testDeferredCycleUsesRegisteredRoot(): void {
        $a=App::fresh(DeferredCycleA::class);
        $this->assertInstanceOf(DeferredCycleB::class,$a->b);
        $this->assertSame($a,$a->b->a);
    }

    /**
     * 延迟自引用拿到根对象本身, 而不是另建一个裸实例
     * @return void
     */
    public function testDeferredSelfInjectsSameInstance(): void {
        $self=App::fresh(DeferredSelf::class);
        $this->assertSame($self,$self->self);
    }

    /**
     * 延迟任务失败后清空队列, 不污染后续请求
     * @return void
     */
    public function testDeferredFailureClearsQueue(): void {
        try {
            App::fresh(DeferredFailureConsumer::class);
            $this->fail('延迟目标不存在时应当抛出 AutowireException');
        } catch(AutowireException $e) {
            $this->assertStringContainsString('Deferred injection failed',$e->getMessage());
            $this->assertStringContainsString('value',$e->getMessage());
            $this->assertStringContainsString(DeferredFailureConsumer::class,$e->getMessage());
        }
        // 若失败任务残留在队列, 这里的 make() 会在刷新时再次抛异常
        $consumer=App::fresh(DeferredPropertyConsumer::class);
        $this->assertInstanceOf(DeferredPropertyDependency::class,$consumer->dependency);
    }

    /**
     * 根构建失败时回滚本轮新增的实例, 不留下半装配对象
     * @return void
     */
    public function testDeferredFailureRollsBackNewRegistrations(): void {
        foreach(array('get','make','fresh') as $method) {
            $container=new Container();
            try {
                $container->$method(DeferredFailureRoot::class);
                $this->fail($method.'() 延迟刷新失败时应当抛出 AutowireException');
            } catch(AutowireException $e) {
                $this->assertStringContainsString('Deferred injection failed',$e->getMessage());
            }
            $this->assertFalse(
                $container->hasInstance(DeferredFailureRoot::class),
                $method.'() 失败后不应留下根对象'
            );
            $this->assertFalse(
                $container->hasInstance(DeferredFailureDependency::class),
                $method.'() 失败后不应留下本轮新建的依赖'
            );
        }

        // build() 本身不登记根对象, 但它的构造依赖仍不能残留在实例表里
        $container=new Container();
        try {
            $container->build(DeferredFailureRoot::class);
            $this->fail('build() 延迟刷新失败时应当抛出 AutowireException');
        } catch(AutowireException $e) {
            $this->assertStringContainsString('Deferred injection failed',$e->getMessage());
        }
        $this->assertFalse($container->hasInstance(DeferredFailureRoot::class));
        $this->assertFalse($container->hasInstance(DeferredFailureDependency::class));
    }

    /**
     * 强制新建失败时恢复原先登记的实例
     * @return void
     */
    public function testDeferredFailureRestoresPreviousRegistration(): void {
        $container=new Container();
        $previous=new DeferredFailureRoot(new DeferredFailureDependency());
        $container->instance(DeferredFailureRoot::class,$previous);

        try {
            $container->fresh(DeferredFailureRoot::class);
            $this->fail('延迟刷新失败时应当抛出 AutowireException');
        } catch(AutowireException $e) {
            $this->assertStringContainsString('Deferred injection failed',$e->getMessage());
        }

        $this->assertSame($previous,$container->get(DeferredFailureRoot::class));
        $this->assertFalse($container->hasInstance(DeferredFailureDependency::class));
    }

    /**
     * 无参代理在首次使用时复用容器中已登记的实例
     * @return void
     */
    public function testProxyReusesRegisteredTargetWithoutConstructorArgs(): void {
        $registered=new ProxyIdentityTarget('registered');
        App::instance(ProxyIdentityTarget::class,$registered);
        $holder=App::make(ProxyIdentityHolder::class);
        $this->assertInstanceOf(DynamicProxy::class,$holder->target);
        $this->assertSame($registered,$holder->target->identity());
        $this->assertSame($registered,$holder->target->instance());
    }

    /**
     * 显式构造参数的代理走 build(), 目标对象不登记到容器
     * @return void
     */
    public function testProxyWithConstructorArgsBuildsWithoutRegistering(): void {
        $proxy=new DynamicProxy(ProxyArgsTarget::class,'from-args');
        $proxy->setContainer(App::getInstance());
        $this->assertSame('from-args',$proxy->value);
        $this->assertFalse(App::getInstance()->hasInstance(ProxyArgsTarget::class));
    }

    /**
     * 代理模式声明为具体类型时必须报错, 不能静默退化
     * @return void
     */
    public function testProxyRejectsIncompatibleDeclaredType(): void {
        $this->expectException(AutowireException::class);
        $this->expectExceptionMessage('not compatible');
        App::make(ProxyWrongTypeConsumer::class);
    }

    /**
     * 代理模式没有显式目标类时必须报错
     * @return void
     */
    public function testProxyRequiresExplicitTarget(): void {
        $this->expectException(AutowireException::class);
        $this->expectExceptionMessage('requires an explicit class name');
        App::make(ProxyNoTargetConsumer::class);
    }

    /**
     * 代理模式不能注入到标量类型
     * @return void
     */
    public function testProxyRejectsBuiltinType(): void {
        $this->expectException(AutowireException::class);
        $this->expectExceptionMessage('not compatible');
        App::make(ProxyBuiltinTypeConsumer::class);
    }

    /**
     * 代理对象无法同时满足目标类型与 DynamicProxy 的交叉类型
     * @return void
     */
    public function testProxyRejectsIntersectionType(): void {
        $this->expectException(AutowireException::class);
        $this->expectExceptionMessage('does not support intersection types');
        App::make(ProxyIntersectionConsumer::class);
    }

    /**
     * 旧 bool 第二参数仍按 Proxy 处理
     * @return void
     */
    public function testLegacyBooleanProxyStillWorks(): void {
        $consumer=App::make(LegacyBooleanProxyConsumer::class);
        $this->assertInstanceOf(DynamicProxy::class,$consumer->target);
        $this->assertInstanceOf(ProxyIdentityTarget::class,$consumer->target->instance());

        $named=App::make(LegacyNamedBooleanProxyConsumer::class);
        $this->assertInstanceOf(DynamicProxy::class,$named->target);
    }

    /**
     * 无 name 的 AutowireMethod 仍按旧生命周期写法执行
     * @return void
     */
    public function testLegacyLifecycleWithoutNameStillWorks(): void {
        $consumer=App::make(LegacyLifecycleConsumer::class);
        $this->assertTrue($consumer->called);
        $this->assertInstanceOf(LegacyLifecycleDependency::class,$consumer->dependency);
    }

    /**
     * 旧生命周期写法不允许 Proxy / Deferred
     * @return void
     */
    public function testLegacyLifecycleRejectsProxyMode(): void {
        $this->expectException(AutowireException::class);
        $this->expectExceptionMessage('legacy lifecycle');
        App::make(LegacyProxyLifecycleConsumer::class);
    }

    /**
     * 生命周期注解与单依赖方法注解不能同挂一处
     * @return void
     */
    public function testLifecycleAndMethodAnnotationsConflict(): void {
        $this->expectException(AutowireException::class);
        $this->expectExceptionMessage('both #[AutowireLifecycle] and #[AutowireMethod]');
        App::make(LifecycleMethodConflictConsumer::class);
    }

    /**
     * Setter 注解与生命周期注解不能同挂一处
     * @return void
     */
    public function testSetterAndLifecycleAnnotationsConflict(): void {
        $this->expectException(AutowireException::class);
        $this->expectExceptionMessage('both #[AutowireSetter] and #[AutowireLifecycle]');
        App::make(SetterLifecycleConflictConsumer::class);
    }

    /**
     * Setter 与单依赖方法不能同挂一处
     * @return void
     */
    public function testSetterAndMethodAnnotationsConflict(): void {
        $this->expectException(AutowireException::class);
        $this->expectExceptionMessage('both #[AutowireSetter] and #[AutowireMethod]');
        App::make(SetterMethodConflictConsumer::class);
    }

    /**
     * 无类型属性 + 显式类名 + Proxy 正常工作
     * @return void
     */
    public function testUntypedProxyPropertyWorks(): void {
        $registered=new ProxyIdentityTarget('untyped');
        App::instance(ProxyIdentityTarget::class,$registered);
        $consumer=App::make(UntypedProxyConsumer::class);
        $this->assertInstanceOf(DynamicProxy::class,$consumer->target);
        $this->assertSame($registered,$consumer->target->identity());
    }

    /**
     * 联合类型包含 DynamicProxy + Proxy 正常工作
     * @return void
     */
    public function testUnionProxyPropertyWorks(): void {
        $registered=new ProxyIdentityTarget('union');
        App::instance(ProxyIdentityTarget::class,$registered);
        $consumer=App::make(UnionProxyConsumer::class);
        $this->assertInstanceOf(DynamicProxy::class,$consumer->target);
        $this->assertSame($registered,$consumer->target->identity());
    }

    /**
     * Setter / 单依赖方法的代理注入正常工作
     * @return void
     */
    public function testSetterAndMethodProxyInjectionWorks(): void {
        $registered=new ProxyIdentityTarget('setter-method');
        App::instance(ProxyIdentityTarget::class,$registered);

        $setter=App::make(ProxySetterConsumer::class);
        $this->assertInstanceOf(DynamicProxy::class,$setter->target);
        $this->assertSame($registered,$setter->target->identity());

        $method=App::make(ProxyMethodConsumer::class);
        $this->assertInstanceOf(DynamicProxy::class,$method->target);
        $this->assertSame($registered,$method->target->identity());
    }

    /**
     * Setter 单参数声明为具体类型时, Proxy 必须报错
     * @return void
     */
    public function testProxySetterRejectsIncompatibleType(): void {
        $this->expectException(AutowireException::class);
        $this->expectExceptionMessage('not compatible');
        App::make(ProxyWrongSetterConsumer::class);
    }

    /**
     * 单依赖方法声明为具体类型时, Proxy 必须报错
     * @return void
     */
    public function testProxyMethodRejectsIncompatibleType(): void {
        $this->expectException(AutowireException::class);
        $this->expectExceptionMessage('not compatible');
        App::make(ProxyWrongMethodConsumer::class);
    }

    /**
     * 旧生命周期写法同样禁止 Deferred
     * @return void
     */
    public function testLegacyLifecycleRejectsDeferredMode(): void {
        $this->expectException(AutowireException::class);
        $this->expectExceptionMessage('legacy lifecycle');
        App::make(LegacyDeferredLifecycleConsumer::class);
    }

    /**
     * 嵌套构建产生的延迟任务不提前刷新, 最外层完成后才执行
     * @return void
     */
    public function testNestedBuildDoesNotFlushDeferredEarly(): void {
        $root=App::fresh(NestedDeferredRoot::class);
        $this->assertTrue($root->nestedWasEmpty,'嵌套对象构建期间不应刷新延迟队列');
        $this->assertInstanceOf(DeferredPropertyDependency::class,$root->nested->dependency);
    }

    /**
     * 生命周期方法的形参继续支持 #[Config]
     * @return void
     */
    public function testLifecycleParameterConfigStillWorks(): void {
        $consumer=App::make(LifecycleConfigConsumer::class);
        $this->assertSame('.data.json',$consumer->ext);
    }

}

/**
 * 延迟属性依赖
 */
final class DeferredPropertyDependency {
}

/**
 * 延迟属性消费者
 */
final class DeferredPropertyConsumer {

    #[AutowireProperty(DeferredPropertyDependency::class,mode:AutowireMode::Deferred)]
    public DeferredPropertyDependency $dependency;

}

/**
 * 延迟 Setter 依赖
 */
final class DeferredSetterDependency {
}

/**
 * 延迟 Setter 消费者
 */
final class DeferredSetterConsumer {

    /**
     * 注入的依赖
     * @var DeferredSetterDependency|null
     */
    public ?DeferredSetterDependency $dependency=null;

    /**
     * Setter 是否被调用
     * @var bool
     */
    public bool $called=false;

    /**
     * 延迟 Setter
     * @param DeferredSetterDependency $dependency 依赖
     * @return void
     */
    #[AutowireSetter(DeferredSetterDependency::class,mode:AutowireMode::Deferred)]
    public function setDependency(DeferredSetterDependency $dependency): void {
        $this->dependency=$dependency;
        $this->called=true;
    }

}

/**
 * 延迟方法依赖
 */
final class DeferredMethodDependency {
}

/**
 * 延迟方法消费者
 */
final class DeferredMethodConsumer {

    /**
     * 注入的依赖
     * @var DeferredMethodDependency|null
     */
    public ?DeferredMethodDependency $dependency=null;

    /**
     * 方法是否被调用
     * @var bool
     */
    public bool $called=false;

    /**
     * 延迟单依赖方法
     * @param DeferredMethodDependency $dependency 依赖
     * @return void
     */
    #[AutowireMethod(DeferredMethodDependency::class,mode:AutowireMode::Deferred)]
    public function inject(DeferredMethodDependency $dependency): void {
        $this->dependency=$dependency;
        $this->called=true;
    }

}

/**
 * 延迟循环依赖 A
 */
final class DeferredCycleA {

    #[AutowireProperty(DeferredCycleB::class,mode:AutowireMode::Deferred)]
    public DeferredCycleB $b;

}

/**
 * 延迟循环依赖 B
 */
final class DeferredCycleB {

    #[AutowireProperty(DeferredCycleA::class,mode:AutowireMode::Deferred)]
    public DeferredCycleA $a;

}

/**
 * 延迟自引用
 */
final class DeferredSelf {

    #[AutowireProperty(DeferredSelf::class,mode:AutowireMode::Deferred)]
    public DeferredSelf $self;

}

/**
 * 延迟目标不存在的消费者
 */
final class DeferredFailureConsumer {

    #[AutowireProperty('No\\Such\\DeferredTarget',mode:AutowireMode::Deferred)]
    public ?object $value=null;

}

/**
 * 延迟失败根对象的构造依赖
 */
final class DeferredFailureDependency {
}

/**
 * 带构造依赖的延迟失败根对象
 */
final class DeferredFailureRoot {

    /**
     * 构造方法
     * @param DeferredFailureDependency $dependency 构造依赖
     */
    public function __construct(public DeferredFailureDependency $dependency) {
    }

    #[AutowireProperty('No\\Such\\DeferredTarget',mode:AutowireMode::Deferred)]
    public ?object $value=null;

}

/**
 * 代理目标
 */
final class ProxyIdentityTarget {

    /**
     * 构造方法
     * @param string $identity 标识
     */
    public function __construct(private string $identity='registered') {
    }

    /**
     * 返回自身, 用于断言代理目标身份
     * @return self
     */
    public function identity(): self {
        return $this;
    }

}

/**
 * 代理持有者
 */
final class ProxyIdentityHolder {

    #[AutowireProperty(ProxyIdentityTarget::class,mode:AutowireMode::Proxy)]
    public DynamicProxy $target;

}

/**
 * 带构造参数的代理目标
 */
final class ProxyArgsTarget {

    /**
     * 构造方法
     * @param string $value 值
     */
    public function __construct(public string $value) {
    }

}

/**
 * 代理声明为目标自身类型的错误用例
 */
final class ProxyWrongTypeConsumer {

    #[AutowireProperty(ProxyIdentityTarget::class,mode:AutowireMode::Proxy)]
    public ProxyIdentityTarget $target;

}

/**
 * 代理缺少显式目标类的错误用例
 */
final class ProxyNoTargetConsumer {

    #[AutowireProperty(mode:AutowireMode::Proxy)]
    public DynamicProxy $target;

}

/**
 * 代理声明为标量类型的错误用例
 */
final class ProxyBuiltinTypeConsumer {

    #[AutowireProperty(ProxyIdentityTarget::class,mode:AutowireMode::Proxy)]
    public string $target='';

}

/**
 * 代理声明为交叉类型的错误用例
 */
final class ProxyIntersectionConsumer {

    #[AutowireProperty(ProxyIdentityTarget::class,mode:AutowireMode::Proxy)]
    public ProxyIdentityTarget&DynamicProxy $target;

}

/**
 * 旧 bool 代理写法
 */
final class LegacyBooleanProxyConsumer {

    #[AutowireProperty(ProxyIdentityTarget::class,true)]
    public DynamicProxy $target;

}

/**
 * 旧具名 proxy 参数写法
 */
final class LegacyNamedBooleanProxyConsumer {

    #[AutowireProperty(ProxyIdentityTarget::class,proxy:true)]
    public DynamicProxy $target;

}

/**
 * 旧生命周期依赖
 */
final class LegacyLifecycleDependency {
}

/**
 * 旧无 name 生命周期写法
 */
final class LegacyLifecycleConsumer {

    /**
     * 注入的依赖
     * @var LegacyLifecycleDependency|null
     */
    public ?LegacyLifecycleDependency $dependency=null;

    /**
     * 是否调用
     * @var bool
     */
    public bool $called=false;

    /**
     * 旧生命周期方法
     * @param LegacyLifecycleDependency $dependency 依赖
     * @return void
     */
    #[AutowireMethod]
    public function boot(LegacyLifecycleDependency $dependency): void {
        $this->dependency=$dependency;
        $this->called=true;
    }

}

/**
 * 旧生命周期写法错误使用 Proxy
 */
final class LegacyProxyLifecycleConsumer {

    #[AutowireMethod(mode:AutowireMode::Proxy)]
    public function boot(): void {
    }

}

/**
 * 生命周期与单依赖方法冲突
 */
final class LifecycleMethodConflictConsumer {

    #[AutowireLifecycle]
    #[AutowireMethod(DeferredMethodDependency::class)]
    public function boot(): void {
    }

}

/**
 * Setter 与生命周期冲突
 */
final class SetterLifecycleConflictConsumer {

    #[AutowireSetter(DeferredSetterDependency::class)]
    #[AutowireLifecycle]
    public function setDependency(DeferredSetterDependency $dependency): void {
    }

}

/**
 * Setter 与单依赖方法冲突
 */
final class SetterMethodConflictConsumer {

    #[AutowireSetter(DeferredSetterDependency::class)]
    #[AutowireMethod(DeferredMethodDependency::class)]
    public function inject(): void {
    }

}

/**
 * 无类型代理属性
 */
final class UntypedProxyConsumer {

    #[AutowireProperty(ProxyIdentityTarget::class,mode:AutowireMode::Proxy)]
    public $target;

}

/**
 * 联合类型包含 DynamicProxy 的代理属性
 */
final class UnionProxyConsumer {

    #[AutowireProperty(ProxyIdentityTarget::class,mode:AutowireMode::Proxy)]
    public DynamicProxy|ProxyIdentityTarget $target;

}

/**
 * 代理 Setter 消费者
 */
final class ProxySetterConsumer {

    /**
     * 代理对象
     * @var DynamicProxy|null
     */
    public ?DynamicProxy $target=null;

    /**
     * 注入代理
     * @param DynamicProxy $target 代理
     * @return void
     */
    #[AutowireSetter(ProxyIdentityTarget::class,mode:AutowireMode::Proxy)]
    public function setTarget(DynamicProxy $target): void {
        $this->target=$target;
    }

}

/**
 * 代理单依赖方法消费者
 */
final class ProxyMethodConsumer {

    /**
     * 代理对象
     * @var DynamicProxy|null
     */
    public ?DynamicProxy $target=null;

    /**
     * 注入代理
     * @param DynamicProxy $target 代理
     * @return void
     */
    #[AutowireMethod(ProxyIdentityTarget::class,mode:AutowireMode::Proxy)]
    public function setTarget(DynamicProxy $target): void {
        $this->target=$target;
    }

}

/**
 * Setter 代理声明为具体类型的错误用例
 */
final class ProxyWrongSetterConsumer {

    #[AutowireSetter(ProxyIdentityTarget::class,mode:AutowireMode::Proxy)]
    public function setTarget(ProxyIdentityTarget $target): void {
    }

}

/**
 * 单依赖方法代理声明为具体类型的错误用例
 */
final class ProxyWrongMethodConsumer {

    #[AutowireMethod(ProxyIdentityTarget::class,mode:AutowireMode::Proxy)]
    public function setTarget(ProxyIdentityTarget $target): void {
    }

}

/**
 * 旧生命周期写法错误使用 Deferred
 */
final class LegacyDeferredLifecycleConsumer {

    #[AutowireMethod(mode:AutowireMode::Deferred)]
    public function boot(): void {
    }

}

/**
 * 嵌套构建中的延迟依赖
 */
final class NestedDeferredDependency {

    #[AutowireProperty(DeferredPropertyDependency::class,mode:AutowireMode::Deferred)]
    public ?DeferredPropertyDependency $dependency=null;

}

/**
 * 嵌套构建的根对象
 */
final class NestedDeferredRoot {

    /**
     * 构造时嵌套对象的延迟属性是否仍为空
     * @var bool
     */
    public bool $nestedWasEmpty;

    /**
     * 构造方法
     * @param NestedDeferredDependency $nested 嵌套对象
     */
    public function __construct(public NestedDeferredDependency $nested) {
        $this->nestedWasEmpty=$nested->dependency===null;
    }

}

/**
 * 生命周期方法参数配置注入
 */
final class LifecycleConfigConsumer {

    /**
     * 配置值
     * @var string|null
     */
    public ?string $ext=null;

    /**
     * 生命周期方法
     * @param string $ext 配置值
     * @return void
     */
    #[AutowireLifecycle]
    public function boot(#[ConfigAttribute('data.ext_name')] string $ext): void {
        $this->ext=$ext;
    }

}
