<?php

namespace Tests;

use PHPUnit\Framework\TestCase;

use AdminService\Coroutine;
use base\ExecutionFlow;
use Fiber;

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

}
