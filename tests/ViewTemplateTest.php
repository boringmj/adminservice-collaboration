<?php

namespace Tests;

use PHPUnit\Framework\TestCase;

use AdminService\Config\Repository;
use AdminService\Exception;
use AdminService\View;
use AdminService\View\AllowedRoots;

use function basename;
use function dirname;
use function file_put_contents;
use function mkdir;
use function rmdir;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

/**
 * 模板解析与渲染测试
 *
 * - 覆盖循环、条件、嵌套、路径与独立行清理
 * - 条件中的字符串字面量、负数与非法 token 按正确语义处理
 */
class ViewTemplateTest extends TestCase {

    /**
     * 测试循环输出
     *
     * @return void
     */
    public function testForeachValues(): void {
        $this->assertSame(
            '<ul><li>a</li><li>b</li></ul>',
            $this->render('<ul>{{foreach $items as $item}}<li>{{$item}}</li>{{/foreach}}</ul>',array(
                'items'=>array('a','b')
            ))
        );
    }

    /**
     * 测试键值循环
     *
     * @return void
     */
    public function testForeachKeyValue(): void {
        $this->assertSame(
            'x=1;y=2;',
            $this->render('{{foreach $items as $key=>$value}}{{$key}}={{$value}};{{/foreach}}',array(
                'items'=>array('x'=>'1','y'=>'2')
            ))
        );
        $this->assertSame(
            'x=1;y=2;',
            $this->render('{{foreach $items as $key => $value}}{{$key}}={{$value}};{{/foreach}}',array(
                'items'=>array('x'=>'1','y'=>'2')
            ))
        );
    }

    /**
     * 测试嵌套条件与循环
     *
     * @return void
     */
    public function testNestedConditionAndForeach(): void {
        $template='{{foreach $items as $item}}{{if $item == \'a\'}}A{{else}}B{{/if}}{{/foreach}}';
        $this->assertSame('AB',$this->render($template,array('items'=>array('a','b'))));
    }

    /**
     * 测试条件中的字符串字面量
     *
     * @return void
     */
    public function testConditionStringLiteral(): void {
        $template='{{if $role == \'admin\'}}YES{{else}}NO{{/if}}';
        $this->assertSame('YES',$this->render($template,array('role'=>'admin')));
        $this->assertSame('NO',$this->render($template,array('role'=>'user')));
    }

    /**
     * 测试条件中的负数
     *
     * @return void
     */
    public function testConditionNegativeNumber(): void {
        $template='{{if $value > -1}}YES{{else}}NO{{/if}}';
        $this->assertSame('YES',$this->render($template,array('value'=>0)));
        $this->assertSame('NO',$this->render($template,array('value'=>-2)));
    }

    /**
     * 测试括号与逻辑运算
     *
     * @return void
     */
    public function testConditionParenthesesAndLogical(): void {
        $template='{{if ($value == 3) || ($value == 5)}}Y{{else}}N{{/if}}';
        $this->assertSame('Y',$this->render($template,array('value'=>5)));
        $this->assertSame('N',$this->render($template,array('value'=>4)));
    }

    /**
     * 测试点分与数组下标路径
     *
     * @return void
     */
    public function testDotAndBracketPaths(): void {
        $template='{{user.name}}|{{user["name"]}}|{{items[0]}}';
        $this->assertSame(
            '&lt;b&gt;|&lt;b&gt;|first',
            $this->render($template,array(
                'user'=>array('name'=>'<b>'),
                'items'=>array('first')
            ))
        );
    }

    /**
     * 测试数组按 PHP 真值参与条件
     *
     * @return void
     */
    public function testConditionArrayTruthiness(): void {
        $this->assertSame('Y',$this->render('{{if $items}}Y{{else}}N{{/if}}',array(
            'items'=>array(1,2)
        )));
        $this->assertSame('N',$this->render('{{if !$items}}Y{{else}}N{{/if}}',array(
            'items'=>array(1,2)
        )));
    }

    /**
     * 测试空 if 标签直接报错
     *
     * @return void
     */
    public function testEmptyIfTagThrows(): void {
        $this->expectException(Exception::class);
        $this->render('{{if}}Y{{/if}}',array());
    }

    /**
     * 测试未选中的分支同样参与语法检查
     *
     * @return void
     */
    public function testDeadBranchSyntaxIsStillChecked(): void {
        $this->expectException(Exception::class);
        $this->render('{{if $ok}}ok{{else}}{{ invalid path }}{{/if}}',array('ok'=>true));
    }

    /**
     * 测试逻辑非与空值比较
     *
     * @return void
     */
    public function testConditionLogicalNotAndNull(): void {
        $template='{{if !$enabled}}OFF{{else}}ON{{/if}}';
        $this->assertSame('OFF',$this->render($template,array('enabled'=>false)));
        $this->assertSame('ON',$this->render($template,array('enabled'=>true)));

        $template='{{if $value == null}}NULL{{else}}SET{{/if}}';
        $this->assertSame('NULL',$this->render($template,array('value'=>null)));
        $this->assertSame('SET',$this->render($template,array('value'=>1)));
    }

    /**
     * 测试条件中的数组下标路径
     *
     * @return void
     */
    public function testConditionBracketPath(): void {
        $template='{{if user["role"] == \'admin\'}}YES{{else}}NO{{/if}}';
        $this->assertSame('YES',$this->render($template,array(
            'user'=>array('role'=>'admin')
        )));
    }

    /**
     * 测试对象属性路径
     *
     * @return void
     */
    public function testObjectPath(): void {
        $user=new \stdClass();
        $user->name='<b>';
        $this->assertSame('&lt;b&gt;',$this->render('{{user.name}}',array('user'=>$user)));
    }

    /**
     * 测试独立行控制标签不产生空行
     *
     * @return void
     */
    public function testStandaloneControlTags(): void {
        $template="A\n{{if \$ok}}\nB\n{{/if}}\nC";
        $this->assertSame("A\nB\nC",$this->render($template,array('ok'=>true)));
    }

    /**
     * 测试非数组集合不输出循环体
     *
     * @return void
     */
    public function testNonArrayLoopRendersNothing(): void {
        $this->assertSame('',$this->render('{{foreach $items as $item}}x{{/foreach}}',array(
            'items'=>'not-array'
        )));
    }

    /**
     * 测试非法条件抛出异常, 不静默当成 false
     *
     * @return void
     */
    public function testInvalidConditionThrows(): void {
        $this->expectException(Exception::class);
        $this->render('{{if $value === 1}}x{{/if}}',array('value'=>1));
    }

    /**
     * 测试未闭合标签抛出异常
     *
     * @return void
     */
    public function testUnclosedTagThrows(): void {
        $this->expectException(Exception::class);
        $this->render('{{if $value}}x',array('value'=>1));
    }

    /**
     * 测试未闭合标签报错携带开标签所在行号
     *
     * @return void
     */
    public function testUnclosedTagReportsOpeningLine(): void {
        try {
            $this->render("A\nB\n{{if \$ok}}\nx",array('ok'=>true));
            $this->fail('未闭合标签应抛异常');
        } catch(Exception $e) {
            $this->assertStringContainsString('line 3',$e->getMessage());
        }
    }

    /**
     * 测试行号按原始源文本统计, 独立行控制标签不造成偏移
     *
     * @return void
     */
    public function testUnclosedLineCountsOriginalSource(): void {
        $template="A\n{{if \$ok}}\nB\n{{/if}}\n{{foreach \$xs as \$x}}\n{{/foreach}}\n{{if \$nope}}\nz";
        try {
            $this->render($template,array('ok'=>true,'xs'=>array()));
            $this->fail('未闭合标签应抛异常');
        } catch(Exception $e) {
            $this->assertStringContainsString('line 7',$e->getMessage());
        }
    }

    /**
     * 测试构造器传入的模板路径在渲染时生效
     *
     * @return void
     */
    public function testConstructorPathRenders(): void {
        $view=new View(dirname(__DIR__).'/AdminService/app/demo/view/Index/view_demo.html',array(
            'name'=>'构造',
            'msg'=>'hello',
            'list1'=>array('a'),
            'list2'=>array(),
            'condition1'=>true,
            'condition2'=>3,
            'count'=>array('value'=>1,'condition'=>5),
            'demo'=>array('list'=>array(1,2))
        ));
        $this->assertStringContainsString('Hi 构造',$view->render());
    }

    /**
     * 测试 setTemplatePath 使旧来源失效, 下一次渲染按新路径加载
     *
     * @return void
     */
    public function testSetTemplatePathTakesEffect(): void {
        $view=new View();
        $view->initWithContent('{{name}}',array('name'=>'a'));
        $view->setTemplatePath(dirname(__DIR__).'/AdminService/app/demo/view/Index/view_demo.html');
        $this->assertStringContainsString('Hi a',$view->render());
    }

    /**
     * 测试 view.allowed_paths 含上级目录段的条目不进入允许根
     *
     * @return void
     */
    public function testAllowedPathsRejectsParentSegment(): void {
        $config=new Repository(array(
            'app'=>array('path'=>sys_get_temp_dir().'/as_view_roots_'.uniqid('',true)),
            'view'=>array('allowed_paths'=>array('../'.basename(dirname(__DIR__))))
        ));
        $roots=AllowedRoots::fromConfig($config);
        $this->assertNotContains(dirname(__DIR__),$roots,'含 .. 段的相对路径不应进入允许根');
    }

    /**
     * 测试数据键名为 True / False / NULL 时按变量路径处理, 不被当作字面量
     *
     * @return void
     */
    public function testConditionDataKeysNamedTrueFalseNull(): void {
        $this->assertSame('n',$this->render('{{if True}}y{{else}}n{{/if}}',array('True'=>false)));
        $this->assertSame('y',$this->render('{{if False}}y{{else}}n{{/if}}',array('False'=>true)));
        $this->assertSame('y',$this->render('{{if NULL}}y{{else}}n{{/if}}',array('NULL'=>'x')));
        $this->assertSame('n',$this->render('{{if $True}}y{{else}}n{{/if}}',array('True'=>false)));
        $this->assertSame('y',$this->render('{{if true}}y{{else}}n{{/if}}',array()));
        $this->assertSame('n',$this->render('{{if null}}y{{else}}n{{/if}}',array()));
    }

    /**
     * 测试无法 JSON 编码的值抛出异常, 不静默输出空串
     *
     * @return void
     */
    public function testUnencodableValueThrows(): void {
        $this->expectException(Exception::class);
        $this->expectExceptionCode(100305);
        $this->render('{{$val}}',array('val'=>array(\INF)));
    }

    /**
     * 测试文件模板经门面与编译缓存渲染
     *
     * @return void
     */
    public function testFileTemplateThroughFacade(): void {
        $view=new View();
        $view->init(dirname(__DIR__).'/AdminService/app/demo/view/Index/view_demo.html',array(
            'name'=>'AdminService',
            'msg'=>'hello',
            'list1'=>array('a'),
            'list2'=>array(),
            'condition1'=>true,
            'condition2'=>3,
            'count'=>array('value'=>1,'condition'=>5),
            'demo'=>array('list'=>array(1,2))
        ));
        $output=$view->render();
        $this->assertStringContainsString('Hi AdminService',$output);
        $this->assertStringContainsString('a',$output);
    }

    /**
     * 测试 initByContent 兼容入口
     *
     * @return void
     */
    public function testInitByContentAlias(): void {
        $view=new View();
        $view->initByContent('{{name}}',array('name'=>'demo'));
        $this->assertSame('demo',$view->render());
    }

    /**
     * 测试模板路径越界被拒绝
     *
     * @return void
     */
    public function testTemplateOutsideAllowedRootsThrows(): void {
        $dir=sys_get_temp_dir().'/as_view_outside_'.uniqid('',true);
        mkdir($dir,0755,true);
        $file=$dir.'/template.html';
        file_put_contents($file,'x');
        try {
            $view=new View();
            $view->init($file);
            $this->fail('模板路径越界应抛异常');
        } catch(Exception $e) {
            $this->assertSame(100304,$e->getCode());
        } finally {
            @unlink($file);
            @rmdir($dir);
        }
    }

    /**
     * 渲染字符串模板
     *
     * @param string $template 模板
     * @param array<string,mixed> $data 数据
     * @return string
     */
    private function render(string $template,array $data): string {
        $view=new View();
        $view->initWithContent($template,$data);
        return $view->render();
    }

}
