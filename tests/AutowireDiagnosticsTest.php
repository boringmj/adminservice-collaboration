<?php

namespace Tests;

use PHPUnit\Framework\TestCase;

use AdminService\Autowire;
use AdminService\ReflectionCache;
use AdminService\exception\AutowireException;
use base\Attribute\AutowireLifecycle;
use base\Attribute\AutowireProperty;

/**
 * 自动装配诊断测试
 *
 * - 联合类型分支只吞"无法构造", 其它异常保留
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
            $union=$e->getPrevious();
            $this->assertInstanceOf(AutowireException::class,$union);
            $this->assertSame('branch a failed',$union->getPrevious()?->getMessage());
        }
    }

    /**
     * 测试生命周期方法缺少参数解析器时抛出受控异常
     *
     * @return void
     */
    public function testLifecycleWithoutArgumentResolverThrows(): void {
        $autowire=new Autowire(new ReflectionCache());
        $this->expectException(AutowireException::class);
        $this->expectExceptionMessage('no ArgumentResolver is configured');
        $autowire->autowire(new LifecycleTarget());
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

final class LifecycleTarget {

    #[AutowireLifecycle]
    public function boot(): void {
    }

}
