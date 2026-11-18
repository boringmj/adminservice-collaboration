<?php

namespace Tests;

use PHPUnit\Framework\TestCase;

use Tests\Fixtures\CircularA;
use Tests\Fixtures\CircularB;
use AdminService\App;
use AdminService\Container;
use AdminService\Exception;
use AdminService\exception\CircularDependencyException;
use base\Attribute\AutowireLifecycle;
use base\Attribute\AutowireProperty;
use base\Attribute\AutowireSetter;

/**
 * 装配器循环依赖用例
 *
 * - 属性相互注入时, 靠**引用传递的构建标识**在第二圈截断
 * - 保护这一行为: 装配器必须把 `$flags` 透传给容器的 make()
 *   (移植过程中曾出现"只传类名、丢掉 $flags"的写法, 那样会无限递归)
 */
class AutowireCycleTest extends TestCase {

    /**
     * 类初始化前执行
     * @return void
     */
    public static function setUpBeforeClass(): void {
        load_test_config();
    }

    /**
     * 测试循环依赖被构建标识截断
     * @return void
     */
    public function testAutowireCycleIsCutByFlags(): void {
        $a=App::fresh(CircularA::class);
        // 第一圈: A 的属性 b 正常装配
        $this->assertInstanceOf(CircularB::class,$a->b);
        // 第二圈: B 的属性 a 由"标识重复则复用"分支给出 —— 是一个**未装配**的裸对象
        $this->assertInstanceOf(CircularA::class,$a->b->a);
        $this->assertNotSame($a,$a->b->a);
        // 裸对象没有被继续装配(类型属性保持未初始化), 说明标识确实透传到了 make()
        // 若 $flags 在半路被丢掉, 这里会继续装配下去直至递归耗尽内存
        $this->assertFalse(isset($a->b->a->b));
    }

    /**
     * 测试构造参数环路抛出受控异常
     * @return void
     */
    public function testConstructorCycleThrowsControlledException(): void {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Circular dependency detected');
        App::make(ConstructorCycleA::class);
    }

    /**
     * 测试 build() 的构造参数环路同样抛出受控异常
     * @return void
     */
    public function testBuildConstructorCycleThrowsControlledException(): void {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Circular dependency detected');
        App::build(ConstructorCycleA::class);
    }

    /**
     * 测试可选自引用且另有必填依赖时, 自引用取默认值
     * @return void
     */
    public function testOptionalSelfConstructorParameterUsesDefault(): void {
        $node=App::make(OptionalSelfWithDependency::class);
        $this->assertInstanceOf(OptionalSelfDependency::class,$node->dep);
        $this->assertNull($node->parent);
    }

    /**
     * 测试可选自引用且没有其它必填参数时取默认值
     * @return void
     */
    public function testOptionalSelfOnlyConstructorParameterUsesDefault(): void {
        $node=App::make(OptionalSelfOnly::class);
        $this->assertNull($node->parent);
    }

    /**
     * 测试可空自引用没有默认值时取 null
     * @return void
     */
    public function testNullableSelfConstructorParameterUsesNull(): void {
        $node=App::make(NullableSelfWithoutDefault::class);
        $this->assertNull($node->parent);
    }

    /**
     * 测试可选构造参数在循环依赖穿过属性注入帧时回落默认值
     * @return void
     */
    public function testOptionalParameterFallsBackWhenCycleCrossesPropertyFrame(): void {
        $holder=App::make(NestedCyclePropertyHolder::class);
        $this->assertInstanceOf(NestedCyclePlainDependency::class,$holder->plain);
        $this->assertNull($holder->dep);
    }

    /**
     * 测试可选构造参数在循环依赖穿过 Setter 注入帧时回落默认值
     * @return void
     */
    public function testOptionalParameterFallsBackWhenCycleCrossesSetterFrame(): void {
        $holder=App::make(NestedCycleSetterHolder::class);
        $this->assertInstanceOf(NestedCyclePlainDependency::class,$holder->plain);
        $this->assertNull($holder->dep);
    }

    /**
     * 测试循环依赖异常穿过注入帧后保持原异常类型
     * @return void
     */
    public function testCircularDependencyExceptionKeepsTypeAcrossFrames(): void {
        $caught=null;
        try {
            App::make(NestedCycleRequiredHolder::class);
            $this->fail('预期抛出循环依赖异常');
        } catch(CircularDependencyException $e) {
            $caught=$e;
        }
        $this->assertNotNull($caught);
    }

    /**
     * 测试生命周期方法显式调用公共 API 时沿用当前构建栈
     * @return void
     */
    public function testExplicitLifecycleRecursionIsCut(): void {
        App::setInstance(new Container());
        $this->assertInstanceOf(ExplicitLifecycleA::class,App::make(ExplicitLifecycleA::class));
    }

}

/**
 * 构造参数循环依赖 A
 */
final class ConstructorCycleA {
    public function __construct(ConstructorCycleB $b) {
    }
}

/**
 * 构造参数循环依赖 B
 */
final class ConstructorCycleB {
    public function __construct(ConstructorCycleA $a) {
    }
}

/**
 * 可选自引用构造参数的依赖
 */
final class OptionalSelfDependency {
}

/**
 * 只有可选自引用构造参数
 */
final class OptionalSelfOnly {
    public function __construct(public ?OptionalSelfOnly $parent=null) {
    }
}

/**
 * 可选自引用构造参数, 另有必填依赖
 */
final class OptionalSelfWithDependency {
    public function __construct(
        public OptionalSelfDependency $dep,
        public ?OptionalSelfWithDependency $parent=null
    ) {
    }
}

/**
 * 可空自引用构造参数, 无默认值
 */
final class NullableSelfWithoutDefault {
    public function __construct(public ?NullableSelfWithoutDefault $parent) {
    }
}

/**
 * 生命周期方法显式调用公共容器的入口
 */
final class ExplicitLifecycleA {
    #[AutowireLifecycle]
    public function boot(): void {
        App::fresh(ExplicitLifecycleB::class);
    }
}

/**
 * 生命周期方法内递归调用自身
 */
final class ExplicitLifecycleB {
    #[AutowireLifecycle]
    public function boot(): void {
        App::fresh(ExplicitLifecycleB::class);
    }
}

/**
 * 嵌套循环回环持有者的必填依赖(使裸实例兜底不可用)
 */
final class NestedCyclePlainDependency {
}

/**
 * 嵌套循环持有者: 属性注入帧回环, 构造参数可选
 */
final class NestedCyclePropertyHolder {

    public function __construct(
        public NestedCyclePlainDependency $plain,
        public ?NestedCycleViaProperty $dep=null
    ) {
    }
}

/**
 * 属性注入帧回环的另一侧(可选持有者)
 */
final class NestedCycleViaProperty {

    #[AutowireProperty(NestedCyclePropertyHolder::class)]
    public NestedCyclePropertyHolder $holder;
}

/**
 * 嵌套循环持有者: 属性注入帧回环, 构造参数必填
 */
final class NestedCycleRequiredHolder {

    public function __construct(
        public NestedCyclePlainDependency $plain,
        public NestedCycleViaRequiredProperty $dep
    ) {
    }
}

/**
 * 属性注入帧回环的另一侧(必填持有者)
 */
final class NestedCycleViaRequiredProperty {

    #[AutowireProperty(NestedCycleRequiredHolder::class)]
    public NestedCycleRequiredHolder $holder;
}

/**
 * 嵌套循环持有者: Setter 注入帧回环, 构造参数可选
 */
final class NestedCycleSetterHolder {

    public function __construct(
        public NestedCyclePlainDependency $plain,
        public ?NestedCycleViaSetter $dep=null
    ) {
    }
}

/**
 * Setter 注入帧回环的另一侧
 */
final class NestedCycleViaSetter {

    public NestedCycleSetterHolder $holder;

    #[AutowireSetter(NestedCycleSetterHolder::class)]
    public function setHolder(NestedCycleSetterHolder $holder): void {
        $this->holder=$holder;
    }
}
