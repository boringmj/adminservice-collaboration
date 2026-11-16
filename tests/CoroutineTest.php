<?php

namespace Tests;

use PHPUnit\Framework\TestCase;

use AdminService\Coroutine;
use Fiber;

/**
 * 协程环境查询与等待测试
 */
class CoroutineTest extends TestCase {

    /**
     * 测试: 非协程环境返回空标识
     *
     * @return void
     */
    public function testOutsideCoroutine(): void {
        $this->assertSame(0,Coroutine::id());
        $this->assertFalse(Coroutine::inside());
        $this->assertNull(Coroutine::key());
    }

    /**
     * 测试: Fiber 不算协程
     *
     * @return void
     */
    public function testFiberIsNotCoroutine(): void {
        $fiber=new Fiber(function(): void {
            $this->assertSame(0,Coroutine::id());
            $this->assertNull(Coroutine::key());
        });
        $fiber->start();
    }

    /**
     * 测试: 非协程环境等待按阻塞方式执行
     *
     * @return void
     */
    public function testSleepFallsBackToBlockingWait(): void {
        $start=microtime(true);
        Coroutine::sleep(0.03);
        $elapsed=microtime(true)-$start;
        $this->assertGreaterThanOrEqual(0.02,$elapsed);
        $this->assertLessThan(1.0,$elapsed);
    }

    /**
     * 测试: 非正数等待立即返回
     *
     * @return void
     */
    public function testSleepIgnoresNonPositiveSeconds(): void {
        $start=microtime(true);
        Coroutine::sleep(0);
        Coroutine::sleep(-1);
        $this->assertLessThan(0.5,microtime(true)-$start);
    }

}
