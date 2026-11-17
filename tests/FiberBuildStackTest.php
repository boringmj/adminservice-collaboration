<?php

namespace Tests;

use PHPUnit\Framework\TestCase;

use AdminService\Container;
use AdminService\exception\AutowireException;
use base\Attribute\AutowireLifecycle;
use base\Attribute\AutowireMode;
use base\Attribute\AutowireProperty;
use Fiber;

/**
 * Fiber 交错构建测试
 *
 * - 构建栈与延迟注入队列必须按执行流隔离
 */
class FiberBuildStackTest extends TestCase {

    /**
     * 测试两个 Fiber 的根构建互不串流
     *
     * @return void
     */
    public function testDeferredQueueIsIsolatedPerFiber(): void {
        $container=new Container();
        $holder=new Fiber(function() use($container): object {
            return $container->make(FiberSuspender::class);
        });
        $holder->start();
        $builder=new Fiber(function() use($container): object {
            return $container->make(FiberDeferredTarget::class);
        });
        try {
            $builder->start();
            $target=$builder->getReturn();
            $this->assertInstanceOf(FiberValue::class,$target->value);
        } finally {
            $holder->resume();
        }
    }

    /**
     * 测试延迟任务队列不被其它 Fiber 刷新
     *
     * @return void
     */
    public function testDeferredQueueIsNotFlushedByAnotherFiber(): void {
        FiberQueuedHolder::$instance=null;
        $container=new Container();
        $holder=new Fiber(function() use($container): object {
            return $container->make(FiberQueuedHolder::class);
        });
        $holder->start();
        $this->assertInstanceOf(FiberQueuedHolder::class,FiberQueuedHolder::$instance);
        $this->assertNull(FiberQueuedHolder::$instance->value);

        $builder=new Fiber(function() use($container): object {
            return $container->make(FiberValue::class);
        });
        $builder->start();
        $this->assertNull(FiberQueuedHolder::$instance->value,'其它 Fiber 的根构建不应刷新本执行流的延迟任务');

        $holder->resume();
        $this->assertInstanceOf(FiberValue::class,FiberQueuedHolder::$instance->value);
    }

    /**
     * 测试延迟任务中的循环依赖仍被识别
     *
     * @return void
     */
    public function testDeferredResolutionKeepsCircularDetection(): void {
        $container=new Container();
        $this->expectException(AutowireException::class);
        $this->expectExceptionMessage('Circular dependency');
        $container->make(DeferredCircularRoot::class);
    }

}

final class FiberSuspender {

    public function __construct() {
        Fiber::suspend('held');
    }

}

final class FiberValue {
}

final class FiberDeferredTarget {

    #[AutowireProperty(FiberValue::class,mode:AutowireMode::Deferred)]
    public ?FiberValue $value=null;

}

final class FiberQueuedHolder {

    public static ?self $instance=null;

    #[AutowireProperty(FiberValue::class,mode:AutowireMode::Deferred)]
    public ?FiberValue $value=null;

    public function __construct() {
        self::$instance=$this;
    }

    #[AutowireLifecycle]
    public function hold(): void {
        Fiber::suspend('held');
    }

}

final class DeferredCircularRoot {

    #[AutowireProperty(DeferredCircularD::class,mode:AutowireMode::Deferred)]
    public ?DeferredCircularD $dependency=null;

}

final class DeferredCircularD {

    public function __construct(DeferredCircularE $dependency) {
    }

}

final class DeferredCircularE {

    #[AutowireProperty(DeferredCircularD::class)]
    public ?DeferredCircularD $dependency=null;

}
