<?php

namespace Tests;

use PHPUnit\Framework\TestCase;

use AdminService\Negotiator;

/**
 * 内容协商测试
 *
 * - 覆盖 Accept 解析、通配、q 值排序与 q=0 拒绝
 */
class NegotiatorTest extends TestCase {

    /**
     * 测试解析Accept头
     * @return void
     */
    public function testParse(): void {
        $this->assertSame(array('application/json'=>1.0),Negotiator::parse('application/json'));
        // 大小写与空白归一化
        $this->assertSame(array('text/html'=>1.0),Negotiator::parse(' TEXT/HTML '));
        // q 权重
        $this->assertSame(
            array('text/html'=>0.8,'application/json'=>1.0),
            Negotiator::parse('text/html;q=0.8, application/json')
        );
        // q=0 保留为 0(表示明确拒绝)
        $this->assertSame(array('application/json'=>0.0),Negotiator::parse('application/json;q=0'));
        // 空头
        $this->assertSame(array(),Negotiator::parse(''));
        $this->assertSame(array(),Negotiator::parse(null));
    }

    /**
     * 测试是否接受某类型
     * @return void
     */
    public function testAccepts(): void {
        $accept=Negotiator::parse('text/html, application/*');
        $this->assertTrue(Negotiator::accepts($accept,'text/html'));
        $this->assertTrue(Negotiator::accepts($accept,'application/json'));
        $this->assertFalse(Negotiator::accepts($accept,'image/png'));
        // 全通配
        $this->assertTrue(Negotiator::accepts(Negotiator::parse('*/*'),'image/png'));
        // 明确拒绝
        $this->assertFalse(Negotiator::accepts(Negotiator::parse('*/*, application/json;q=0'),'application/json'));
        // 空 Accept 解析结果不匹配任何类型(调用方按"无约束"处理)
        $this->assertFalse(Negotiator::accepts(array(),'application/json'));
    }

    /**
     * 测试选出最优候选
     * @return void
     */
    public function testBest(): void {
        $candidates=array('*/*','text/html','text/plain','application/json');
        // 精确匹配
        $this->assertSame('application/json',Negotiator::best(Negotiator::parse('application/json'),$candidates));
        // 子类通配
        $this->assertSame('text/html',Negotiator::best(Negotiator::parse('text/*'),$candidates));
        // q 高者优先
        $this->assertSame('text/plain',Negotiator::best(
            Negotiator::parse('application/json;q=0.5, text/plain;q=0.9'),
            $candidates
        ));
        // q 相同时类型更具体者优先(application/json 精确 > */* 通配)
        $this->assertSame('application/json',Negotiator::best(
            Negotiator::parse('*/*, application/json'),
            $candidates
        ));
        // 明确拒绝后回落到其他候选
        $this->assertSame('text/html',Negotiator::best(
            Negotiator::parse('text/*, application/json;q=0'),
            array('application/json','text/html')
        ));
        // 无可接受候选
        $this->assertNull(Negotiator::best(Negotiator::parse('image/png'),$candidates));
        $this->assertNull(Negotiator::best(array(),$candidates));
        // 含通配符的候选不参与选择: 全通配时取第一个具体候选
        $this->assertSame('text/html',Negotiator::best(Negotiator::parse('*/*'),$candidates));
    }

}
