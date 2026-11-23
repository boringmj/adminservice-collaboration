<?php

namespace Tests;

use PHPUnit\Framework\TestCase;

use AdminService\Config;
use AdminService\Exception;
use AdminService\View;
use AdminService\View\Source\FileSource;
use stdClass;

use function chdir;
use function dirname;
use function escapeshellarg;
use function fclose;
use function file_put_contents;
use function getcwd;
use function in_array;
use function is_file;
use function is_resource;
use function mkdir;
use function proc_close;
use function proc_open;
use function rmdir;
use function stream_get_contents;
use function stream_get_wrappers;
use function stream_wrapper_register;
use function str_replace;
use function sys_get_temp_dir;
use function tempnam;
use function touch;
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
     * 测试文件来源指纹能探测其他进程的修改
     *
     * - PHP 的 stat 结果在进程内缓存; 不清缓存时常驻进程探测不到其他进程的部署更新
     *
     * @return void
     */
    public function testFileSourceFingerprintDetectsExternalModification(): void {
        $file=tempnam(sys_get_temp_dir(),'view_fp_');
        file_put_contents($file,'A');
        touch($file,1000);
        try {
            $source=new FileSource($file);
            $before=$source->fingerprint();
            // 同进程修改会自动清 stat 缓存, 必须经另一进程修改
            $process=proc_open(
                array(PHP_BINARY,'-d','display_errors=0',__DIR__.'/fixtures/file_modify.php',$file,'2000'),
                array(1=>array('pipe','w'),2=>array('pipe','w')),
                $pipes
            );
            $this->assertTrue(is_resource($process),'无法启动子进程');
            $stderr=(string)stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $this->assertSame(0,proc_close($process),$stderr);
            $this->assertNotSame($before,$source->fingerprint(),'其他进程修改文件后指纹应变化');
        } finally {
            if(is_file($file))
                unlink($file);
        }
    }

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
     * 测试配置的允许根目录可以渲染模板
     *
     * @return void
     */
    public function testInitAcceptsConfiguredAllowedPath(): void {
        $dir=sys_get_temp_dir().'/as_view_allowed_'.uniqid('',true);
        mkdir($dir,0755,true);
        $file=$dir.'/template.html';
        file_put_contents($file,'Hi {{name}}');
        $configs=Config::all();
        $configs['view']['allowed_paths']=array($dir);
        set_config($configs);
        try {
            $view=new View();
            $view->init($file,array('name'=>'demo'));
            $this->assertSame('Hi demo',$view->render());
        } finally {
            load_framework_config();
            @unlink($file);
            @rmdir($dir);
        }
    }

    /**
     * 测试相对允许根目录按项目根解析, 不依赖进程工作目录
     *
     * @return void
     */
    public function testRelativeAllowedPathResolvesFromProjectRoot(): void {
        $relative='AdminService/runtime/view_allowed_'.uniqid('',true);
        $dir=dirname(__DIR__).'/'.$relative;
        mkdir($dir,0755,true);
        $file=$dir.'/template.html';
        file_put_contents($file,'relative');
        $configs=Config::all();
        $configs['view']['allowed_paths']=array($relative);
        set_config($configs);
        $cwd=getcwd();
        try {
            chdir(sys_get_temp_dir());
            $view=new View();
            $view->init($file);
            $this->assertSame('relative',$view->render());
        } finally {
            chdir($cwd);
            load_framework_config();
            @unlink($file);
            @rmdir($dir);
        }
    }

    /**
     * 测试裸文件名错误模板不会把项目根加入允许目录
     *
     * @return void
     */
    public function testBareErrorTemplateDoesNotAllowProjectRoot(): void {
        $config=new \AdminService\Config\Repository(array(
            'app'=>array(
                'path'=>'/nonexistent-app',
                'error_template'=>'error.html'
            ),
            'view'=>array('allowed_paths'=>array())
        ));
        $roots=\AdminService\View\AllowedRoots::fromConfig($config);
        $this->assertNotContains(dirname(__DIR__),$roots);
        $this->assertSame(array(),$roots);

        $config=new \AdminService\Config\Repository(array(
            'app'=>array(
                'path'=>'/nonexistent-app',
                'error_template'=>'../error.html'
            ),
            'view'=>array('allowed_paths'=>array())
        ));
        $this->assertSame(array(),\AdminService\View\AllowedRoots::fromConfig($config));
    }

    /**
     * 测试所有允许根目录都无法解析时拒绝模板
     *
     * @return void
     */
    public function testUnavailableRootsRejectTemplate(): void {
        $file=tempnam(sys_get_temp_dir(),'as_view_roots_');
        $configs=Config::all();
        $configs['app']['path']='/nonexistent-app';
        $configs['app']['error_template']='/nonexistent-root/error.html';
        $configs['view']['allowed_paths']=array();
        set_config($configs);
        try {
            $view=new View();
            $this->expectException(Exception::class);
            $this->expectExceptionCode(100304);
            $view->init($file);
        } finally {
            load_framework_config();
            @unlink($file);
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

    /**
     * 允许根存在时, 本地缺失模板报文件不存在
     *
     * @return void
     */
    public function testInitMissingLocalTemplateReportsNotFound(): void {
        $path=dirname(__DIR__).'/AdminService/app/demo/view/Index/missing_'.uniqid('',true).'.html';
        $view=new View();
        $this->expectException(Exception::class);
        $this->expectExceptionCode(100301);
        $view->init($path);
    }

    /**
     * 已存在但位于允许根之外的文件仍按路径越界拒绝
     *
     * @return void
     */
    public function testInitExistingFileOutsideRootsIsRejected(): void {
        $file=tempnam(sys_get_temp_dir(),'as_view_outside_');
        file_put_contents($file,'x');
        try {
            $view=new View();
            $this->expectException(Exception::class);
            $this->expectExceptionCode(100304);
            $view->init($file);
        } finally {
            @unlink($file);
        }
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
