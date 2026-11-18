<?php

namespace Tests;

use PHPUnit\Framework\TestCase;

use AdminService\Coroutine;
use base\ExecutionFlow;
use Fiber;

use function gc_collect_cycles;
use function substr;

/**
 * 执行流标识测试
 */
class ExecutionFlowTest extends TestCase {

    /**
     * 测试: 主流程标识固定为 main
     *
     * @return void
     */
    public function testMainFlowKey(): void {
        $this->assertSame('main',ExecutionFlow::key());
    }

    /**
     * 测试: Fiber 按对象身份区分, 同一 Fiber 内保持不变
     *
     * @return void
     */
    public function testFiberKeysAreStableAndDistinct(): void {
        $first=null;
        $first_again=null;
        $second=null;
        $one=new Fiber(function() use (&$first,&$first_again): void {
            $first=ExecutionFlow::key();
            Fiber::suspend();
            $first_again=ExecutionFlow::key();
        });
        $two=new Fiber(function() use (&$second): void {
            $second=ExecutionFlow::key();
        });
        $one->start();
        $two->start();
        $one->resume();

        $this->assertStringStartsWith('fiber:',(string)$first);
        $this->assertSame($first,$first_again);
        $this->assertStringStartsWith('fiber:',(string)$second);
        $this->assertNotSame($first,$second);
    }

    /**
     * 测试: Fiber 内 Coroutine::key() 仍为空, 与执行流标识区分
     *
     * @return void
     */
    public function testCoroutineKeyStaysNullInsideFiber(): void {
        $fiber=new Fiber(function(): void {
            $this->assertNull(Coroutine::key());
            $this->assertStringStartsWith('fiber:',ExecutionFlow::key());
        });
        $fiber->start();
    }

    /**
     * 测试: 执行流判活
     *
     * - main 恒存活; Fiber 随对象存活; 协程驱动不可用时无法判定按存活处理
     *
     * @return void
     */
    public function testAliveDetectsAbandonedFiber(): void {
        $this->assertTrue(ExecutionFlow::alive('main'));
        $this->assertTrue(ExecutionFlow::alive('swoole:999'),'驱动不可用时无法判定, 应按存活处理');

        $key=null;
        $fiber=new Fiber(function() use (&$key): void {
            $key=ExecutionFlow::key();
        });
        $fiber->start();
        $this->assertTrue(ExecutionFlow::alive($key));

        unset($fiber);
        gc_collect_cycles();
        $this->assertFalse(ExecutionFlow::alive($key),'Fiber 对象销毁后应判定为已消亡');
    }

    /**
     * 测试: Fiber 标识单调递增, 已消亡 Fiber 的标识不被复用
     *
     * @return void
     */
    public function testFiberKeysAreMonotonicAndNotReused(): void {
        $first=null;
        $fiber=new Fiber(function() use (&$first): void {
            $first=ExecutionFlow::key();
        });
        $fiber->start();
        unset($fiber);
        gc_collect_cycles();

        $second=null;
        $fiber=new Fiber(function() use (&$second): void {
            $second=ExecutionFlow::key();
        });
        $fiber->start();

        $this->assertNotSame($first,$second);
        $this->assertGreaterThan((int)substr((string)$first,6),(int)substr((string)$second,6));
    }

}
