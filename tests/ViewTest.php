<?php

namespace Tests;

use PHPUnit\Framework\TestCase;

use AdminService\Exception;
use AdminService\View;
use AdminService\View\Source\FileSource;
use stdClass;

use function file_put_contents;
use function in_array;
use function mkdir;
use function rmdir;
use function stream_get_wrappers;
use function stream_wrapper_register;
use function str_replace;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

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

    /**
     * 无法解析为普通文件的模板路径必须拒绝
     *
     * @return void
     */
    public function testInitRejectsUnresolvableTemplatePath(): void {
        UnreadableViewStream::register();
        $view=new View();
        $this->expectException(Exception::class);
        $this->expectExceptionCode(100304);
        $view->init('unreadableview://template');
    }

    /**
     * 包装器路径不能绕过模板根限制
     *
     * @return void
     */
    public function testInitRejectsStreamWrapperOutsideRoots(): void {
        $dir=sys_get_temp_dir().'/as_view_stream_'.uniqid('',true);
        mkdir($dir,0755,true);
        $file=$dir.'/template.html';
        file_put_contents($file,'x');
        try {
            $view=new View();
            $this->expectException(Exception::class);
            $this->expectExceptionCode(100304);
            $view->init('file:///'.str_replace('\\','/',$file));
        } finally {
            @unlink($file);
            @rmdir($dir);
        }
    }

    /**
     * 文件来源自身在读取失败时抛受控异常
     *
     * @return void
     */
    public function testFileSourceThrowsWhenTemplateCannotBeRead(): void {
        UnreadableViewStream::register();
        $this->expectException(Exception::class);
        $this->expectExceptionCode(100302);
        new FileSource('unreadableview://template');
    }

}

/**
 * 让 is_file() 认为存在、file_get_contents() 固定失败的测试流
 */
final class UnreadableViewStream {

    /**
     * 文件路径(流协议不关心)
     * @var string
     */
    public $context;

    /**
     * 注册流协议
     *
     * @return void
     */
    public static function register(): void {
        if(!in_array('unreadableview',stream_get_wrappers(),true))
            stream_wrapper_register('unreadableview',self::class);
    }

    /**
     * 返回普通文件 stat, 让 is_file() 为真
     *
     * @param string $path
     * @param int $flags
     * @return array<string,int>
     */
    public function url_stat(string $path,int $flags): array {
        return array(
            'dev'=>0,
            'ino'=>0,
            'mode'=>0100644,
            'nlink'=>1,
            'uid'=>0,
            'gid'=>0,
            'rdev'=>0,
            'size'=>0,
            'atime'=>0,
            'mtime'=>0,
            'ctime'=>0,
            'blksize'=>-1,
            'blocks'=>-1
        );
    }

    /**
     * 固定打开失败, 让 file_get_contents() 返回 false
     *
     * @param string $path
     * @param string $mode
     * @param int $options
     * @param string|null $opened_path
     * @return bool
     */
    public function stream_open(string $path,string $mode,int $options,?string &$opened_path): bool {
        return false;
    }

}
