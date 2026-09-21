<?php

namespace Tests;

use PHPUnit\Framework\TestCase;

use Tests\Fixtures\CircularA;
use Tests\Fixtures\CircularB;
use AdminService\App;

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
        $a=App::make(CircularA::class,true);
        // 第一圈: A 的属性 b 正常装配
        $this->assertInstanceOf(CircularB::class,$a->b);
        // 第二圈: B 的属性 a 由"标识重复则复用"分支给出 —— 是一个**未装配**的裸对象
        $this->assertInstanceOf(CircularA::class,$a->b->a);
        $this->assertNotSame($a,$a->b->a);
        // 裸对象没有被继续装配(类型属性保持未初始化), 说明标识确实透传到了 make()
        // 若 $flags 在半路被丢掉, 这里会继续装配下去直至递归耗尽内存
        $this->assertFalse(isset($a->b->a->b));
    }

}
