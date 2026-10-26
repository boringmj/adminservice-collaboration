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
        // vendor JSON 归一为标准 JSON, 原类型保留
        $vendor=Negotiator::parse('application/vnd.api+json;q=0.7');
        $this->assertSame(0.7,$vendor['application/vnd.api+json']);
        $this->assertSame(0.7,$vendor['application/json']);
        // 显式 application/json;q=0 不能被 vendor 变体覆盖
        $vendor=Negotiator::parse('application/vnd.api+json;q=0.5, application/json;q=0');
        $this->assertSame(0.5,$vendor['application/vnd.api+json']);
        $this->assertSame(0.0,$vendor['application/json']);
        $vendor=Negotiator::parse('application/json;q=0, application/vnd.api+json;q=0.9');
        $this->assertSame(0.0,$vendor['application/json']);
        $this->assertSame(0.9,$vendor['application/vnd.api+json']);
        // 常见后缀通配 `application/*+json` 兼容为标准 JSON
        $wildcard=Negotiator::parse('application/*+json');
        $this->assertSame(1.0,$wildcard['application/*+json']);
        $this->assertSame(1.0,$wildcard['application/json']);
        // 非 application 的 `+json` 不按标准 JSON 处理
        $this->assertArrayNotHasKey('application/json',Negotiator::parse('text/foo+json'));
        // q 参数大小写不敏感, 允许等号两侧空白
        $q=Negotiator::parse('application/json;Q=0, text/html; q = 0.8');
        $this->assertSame(0.0,$q['application/json']);
        $this->assertSame(0.8,$q['text/html']);
        // 非法 q 保持默认权重; 越界 q 收敛到 0..1
        $this->assertSame(1.0,Negotiator::parse('application/json;q=foo')['application/json']);
        $this->assertSame(0.0,Negotiator::parse('application/json;q=-1')['application/json']);
        $this->assertSame(1.0,Negotiator::parse('application/json;q=2')['application/json']);
        // 空头
        $this->assertSame(array(),Negotiator::parse(''));
        $this->assertSame(array(),Negotiator::parse(null));
    }

    /**
     * 测试引号内的逗号/分号不参与切分
     * @return void
     */
    public function testQuotedParametersDoNotSplitHeader(): void {
        $accept=Negotiator::parse(
            'text/html;profile="a,b";q=0.5, application/json;foo="x;y";q=0.9'
        );
        $this->assertSame(0.5,$accept['text/html']);
        $this->assertSame(0.9,$accept['application/json']);
        // 引号内的分号不能拆出伪 q 参数
        $accept=Negotiator::parse('application/json;foo="x;q=0.5;y"');
        $this->assertSame(1.0,$accept['application/json']);
        // 转义引号不结束参数值, 引号内的分号同样保留
        $accept=Negotiator::parse('application/json;profile="a\\"b;c";q=0.4');
        $this->assertSame(0.4,$accept['application/json']);
        // 引号未闭合时退化为逐分隔符切分, 不抛异常
        $accept=Negotiator::parse('application/json;profile="unterminated;q=0.2');
        $this->assertSame(0.2,$accept['application/json']);
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
        // vendor JSON 视为标准 JSON, 两侧都归一
        $this->assertTrue(Negotiator::accepts(Negotiator::parse('application/vnd.api+json'),'application/json'));
        $this->assertTrue(Negotiator::accepts(Negotiator::parse('application/json'),'application/vnd.api+json'));
        // 显式拒绝标准 JSON 时, vendor 变体仍可按自己的权重被接受
        $mixed=Negotiator::parse('application/json;q=0, application/vnd.api+json;q=0.9');
        $this->assertFalse(Negotiator::accepts($mixed,'application/json'));
        $this->assertTrue(Negotiator::accepts($mixed,'application/vnd.api+json'));
        // 后缀通配也接受标准 JSON
        $this->assertTrue(Negotiator::accepts(Negotiator::parse('application/*+json'),'application/json'));
        // 非 application 的 `+json` 不与标准 JSON 互通
        $this->assertFalse(Negotiator::accepts(Negotiator::parse('text/foo+json'),'application/json'));
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
        // 后缀通配兼容标准 JSON
        $this->assertSame('application/json',Negotiator::best(Negotiator::parse('application/*+json'),$candidates));
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
        // vendor JSON 与标准 JSON 等价
        $this->assertSame('application/json',Negotiator::best(
            Negotiator::parse('application/vnd.api+json'),
            $candidates
        ));
        $this->assertNull(Negotiator::best(
            Negotiator::parse('application/vnd.api+json;q=0'),
            array('application/json')
        ));
        // 候选侧 vendor JSON 同样归一, 返回原始候选名
        $this->assertSame('application/vnd.api+json',Negotiator::best(
            Negotiator::parse('application/json'),
            array('application/vnd.api+json')
        ));
        // 显式 q=0 的标准 JSON 被拒绝, 但同头的 vendor 候选不受影响
        $mixed=Negotiator::parse('application/json;q=0, application/vnd.api+json;q=0.9');
        $this->assertNull(Negotiator::best($mixed,array('application/json')));
        $this->assertSame('application/vnd.api+json',Negotiator::best(
            $mixed,
            array('application/json','application/vnd.api+json')
        ));
        // 非 application 的 `+json` 不能被标准 JSON 命中
        $this->assertNull(Negotiator::best(
            Negotiator::parse('text/foo+json'),
            array('application/json')
        ));
        // 无可接受候选
        $this->assertNull(Negotiator::best(Negotiator::parse('image/png'),$candidates));
        $this->assertNull(Negotiator::best(array(),$candidates));
        // 含通配符的候选不参与选择: 全通配时取第一个具体候选
        $this->assertSame('text/html',Negotiator::best(Negotiator::parse('*/*'),$candidates));
    }

}
