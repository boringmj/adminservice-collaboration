<?php

namespace Tests;

use PHPUnit\Framework\TestCase;

use AdminService\Autowire;
use AdminService\Container;
use AdminService\ReflectionCache;
use AdminService\exception\AutowireException;
use base\Attribute\AutowireLifecycle;
use base\Attribute\AutowireProperty;

/**
 * 自动装配诊断测试
 *
 * - 联合类型分支只吞"无法构造", 其它异常保留(含容器接线路径)
 * - 生命周期方法缺少 ArgumentResolver 时抛出清晰异常
 */
class AutowireDiagnosticsTest extends TestCase {

    /**
     * 测试联合类型回退到可构造分支
     *
     * @return void
     */
    public function testUnionFallsBackToConstructibleBranch(): void {
        $autowire=new Autowire(new ReflectionCache());
        $autowire->setInstanceResolver(function(string $class,bool $is_force,array &$flags): object {
            if($class===UnionBranchA::class)
                throw new AutowireException('branch a failed');
            return new UnionBranchB();
        });
        $target=new UnionTarget();
        $autowire->autowire($target);
        $this->assertInstanceOf(UnionBranchB::class,$target->dep);
    }

    /**
     * 测试必需构造参数导致的分支失败会回退
     *
     * @return void
     */
    public function testUnionFallsBackOnArgumentCountError(): void {
        $autowire=new Autowire(new ReflectionCache());
        $target=new RequiredCtorUnionTarget();
        $autowire->autowire($target);
        $this->assertInstanceOf(UnionBranchB::class,$target->dep);
    }

    /**
     * 测试类不可用异常会触发分支回退
     *
     * @return void
     */
    public function testUnionFallsBackOnClassUnavailable(): void {
        $autowire=new Autowire(new ReflectionCache());
        $autowire->setInstanceResolver(function(string $class,bool $is_force,array &$flags): object {
            if($class===UnionBranchA::class)
                throw new \AdminService\exception\ClassUnavailableException('unavailable');
            return new UnionBranchB();
        });
        $target=new UnionTarget();
        $autowire->autowire($target);
        $this->assertInstanceOf(UnionBranchB::class,$target->dep);
    }

    /**
     * 测试联合类型分支的其它异常不被吞掉
     *
     * @return void
     */
    public function testUnionBranchRealFailurePropagates(): void {
        $autowire=new Autowire(new ReflectionCache());
        $autowire->setInstanceResolver(function(string $class,bool $is_force,array &$flags): object {
            if($class===UnionBranchA::class)
                throw new \RuntimeException('boom');
            return new UnionBranchB();
        });
        $target=new UnionTarget();
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('boom');
        $autowire->autowire($target);
    }

    /**
     * 测试联合类型全部失败时保留首个失败原因
     *
     * @return void
     */
    public function testUnionFailureKeepsFirstCause(): void {
        $autowire=new Autowire(new ReflectionCache());
        $autowire->setInstanceResolver(function(string $class,bool $is_force,array &$flags): object {
            throw new AutowireException($class===UnionBranchA::class?'branch a failed':'branch b failed');
        });
        try {
            $autowire->autowire(new UnionTarget());
            $this->fail('联合类型全部失败时应抛出异常');
        } catch(AutowireException $e) {
            $this->assertSame(array(UnionBranchA::class,UnionBranchB::class),$e->getData()['types']);
            $union=$e->getPrevious();
            $this->assertInstanceOf(AutowireException::class,$union);
            $this->assertSame('branch a failed',$union->getPrevious()?->getMessage());
        }
    }

    /**
     * 测试容器接线路径下联合类型回退到可构造分支
     *
     * @return void
     */
    public function testUnionFallsBackThroughContainerWiring(): void {
        $container=new Container();
        $target=$container->make(ContainerUnionTarget::class);
        $this->assertInstanceOf(ContainerUnionBranchB::class,$target->dep);
    }

    /**
     * 测试容器接线路径下分支的真实异常不被吞掉
     *
     * @return void
     */
    public function testUnionRealFailurePropagatesThroughContainerWiring(): void {
        $container=new Container();
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('boom');
        $container->make(ContainerUnionThrowingTarget::class);
    }

    /**
     * 测试无参生命周期方法不需要参数解析器
     *
     * @return void
     */
    public function testParameterlessLifecycleDoesNotRequireArgumentResolver(): void {
        $autowire=new Autowire(new ReflectionCache());
        $target=new LifecycleTarget();
        $autowire->autowire($target);
        $this->assertTrue($target->booted);
    }

    /**
     * 测试带参生命周期方法缺少参数解析器时抛出受控异常
     *
     * @return void
     */
    public function testLifecycleWithoutArgumentResolverThrows(): void {
        $autowire=new Autowire(new ReflectionCache());
        $this->expectException(AutowireException::class);
        $this->expectExceptionMessage('no ArgumentResolver is configured');
        $autowire->autowire(new LifecycleWithParamTarget());
    }

}

final class UnionBranchA {
}

final class UnionBranchB {
}

final class UnionTarget {

    #[AutowireProperty]
    public UnionBranchA|UnionBranchB $dep;

}

final class RequiredCtorBranch {

    public function __construct(\stdClass $dependency) {
    }

}

final class RequiredCtorUnionTarget {

    #[AutowireProperty]
    public RequiredCtorBranch|UnionBranchB $dep;

}

final class ContainerUnionUnresolvableBranch {

    public function __construct(\No\Such\UnionDependency $dep) {
    }

}

final class ContainerUnionBranchB {
}

final class ContainerUnionTarget {

    #[AutowireProperty]
    public ContainerUnionUnresolvableBranch|ContainerUnionBranchB $dep;

}

final class ContainerUnionThrowingBranch {

    public function __construct() {
        throw new \RuntimeException('boom');
    }

}

final class ContainerUnionThrowingTarget {

    #[AutowireProperty]
    public ContainerUnionThrowingBranch|ContainerUnionBranchB $dep;

}

final class LifecycleTarget {

    public bool $booted=false;

    #[AutowireLifecycle]
    public function boot(): void {
        $this->booted=true;
    }

}

final class LifecycleWithParamTarget {

    #[AutowireLifecycle]
    public function boot(\stdClass $dependency): void {
    }

}
