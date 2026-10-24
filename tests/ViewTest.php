<?php

namespace Tests;

use PHPUnit\Framework\TestCase;

use AdminService\View;
use stdClass;

/**
 * View 变量输出测试
 *
 * - 默认 {{var}} 必须转义，包括数组/对象 JSON 中的 HTML
 * - {{{var}}} / {{!var}} 保持不转义语义
 */
class ViewTest extends TestCase {

    /**
     * 默认标签输出数组时必须转义 HTML
     * @return void
     */
    public function testArrayValueIsEscapedByDefault(): void {
        $view=new View();
        $view->initWithContent(
            '{{payload}}',
            array('payload'=>array('html'=>'<script>alert(1)</script>'))
        );
        $output=$view->render();
        $this->assertStringNotContainsString('<script>',$output);
        $this->assertStringContainsString('&lt;script&gt;',$output);
    }

    /**
     * 默认标签输出对象时必须转义 HTML
     * @return void
     */
    public function testObjectValueIsEscapedByDefault(): void {
        $payload=new stdClass();
        $payload->html='<script>alert(1)</script>';
        $view=new View();
        $view->initWithContent('{{payload}}',array('payload'=>$payload));
        $output=$view->render();
        $this->assertStringNotContainsString('<script>',$output);
        $this->assertStringContainsString('&lt;script&gt;',$output);
    }

    /**
     * 原始输出标签仍不转义数组
     * @return void
     */
    public function testRawArrayValueStaysUnescaped(): void {
        $view=new View();
        $view->initWithContent(
            '{{{payload}}}',
            array('payload'=>array('html'=>'<script>alert(1)</script>'))
        );
        $this->assertStringContainsString('<script>',$view->render());
    }

    /**
     * {{!var}} 原始输出标签仍不转义数组
     * @return void
     */
    public function testRawBangArrayValueStaysUnescaped(): void {
        $view=new View();
        $view->initWithContent(
            '{{!payload}}',
            array('payload'=>array('html'=>'<script>alert(1)</script>'))
        );
        $this->assertStringContainsString('<script>',$view->render());
    }

    /**
     * 标量默认转义行为不回退
     * @return void
     */
    public function testScalarValueIsStillEscaped(): void {
        $view=new View();
        $view->initWithContent('{{name}}',array('name'=>'<b>demo</b>'));
        $this->assertSame('&lt;b&gt;demo&lt;/b&gt;',$view->render());
    }

}
