<?php

namespace Tests;

use PHPUnit\Framework\TestCase;

use AdminService\Cache;
use AdminService\Config\Repository;
use AdminService\View\Cache\FileCache;
use AdminService\View\Compiler\PhpCompiler;
use AdminService\View\Source\FileSource;
use base\View\CodeTemplateCompilerInterface;
use base\View\CompiledTemplateInterface;
use base\View\TemplateSourceInterface;

use function var_export;

use function clearstatcache;
use function count;
use function dirname;
use function file_get_contents;
use function filemtime;
use function file_put_contents;
use function is_dir;
use function is_file;
use function is_link;
use function mkdir;
use function rmdir;
use function scandir;
use function str_replace;
use function symlink;
use function sys_get_temp_dir;
use function time;
use function touch;
use function uniqid;
use function unlink;

/**
 * 模板编译缓存测试
 */
class ViewCacheTest extends TestCase {

    /**
     * 临时目录
     * @var string
     */
    private string $dir;

    /**
     * 模板文件
     * @var string
     */
    private string $template;

    /**
     * 缓存目录
     * @var string
     */
    private string $cache_dir;

    /**
     * 初始化临时文件
     *
     * @return void
     */
    protected function setUp(): void {
        $this->dir=sys_get_temp_dir().'/as_view_cache_'.uniqid('',true);
        mkdir($this->dir,0755,true);
        $this->template=$this->dir.'/template.html';
        $this->cache_dir=$this->dir.'/cache/compile/view';
        file_put_contents($this->template,'first {{$name}}');
    }

    /**
     * 清理临时目录
     *
     * @return void
     */
    protected function tearDown(): void {
        $this->removePath($this->dir);
    }

    /**
     * 测试缓存命中与源文件变化后重建
     *
     * @return void
     */
    public function testCacheHitAndRebuild(): void {
        $cache=new FileCache($this->config('always'));
        $compiler=new PhpCompiler();
        $this->assertSame('first A',$cache->load(new FileSource($this->template),$compiler)->render(array('name'=>'A')));
        $this->assertTrue(is_dir($this->cache_dir));
        $files=scandir($this->cache_dir);
        $this->assertIsArray($files);
        $this->assertGreaterThan(2,count($files));

        file_put_contents($this->template,'second {{$name}}');
        touch($this->template,time()+3);
        $this->assertSame('second B',$cache->load(new FileSource($this->template),$compiler)->render(array('name'=>'B')));
    }

    /**
     * 测试自定义编译器不会复用默认编译器或其他自定义编译器的产物
     *
     * @return void
     */
    public function testCompilersDoNotShareCachedArtifacts(): void {
        $cache=new FileCache($this->config('always'));
        $default=new PhpCompiler();
        $first=new ViewCacheFixedCompiler('one');
        $second=new ViewCacheOtherCompiler('two');
        $this->assertSame('first A',$cache->load(new FileSource($this->template),$default)->render(array('name'=>'A')));
        $this->assertSame('one',$cache->load(new FileSource($this->template),$first)->render(array()));
        $this->assertSame('first A',$cache->load(new FileSource($this->template),$default)->render(array('name'=>'A')));
        $this->assertSame('two',$cache->load(new FileSource($this->template),$second)->render(array()));
        $this->assertSame('one',$cache->load(new FileSource($this->template),$first)->render(array()));
    }

    /**
     * 测试顶层视图缓存目录为符号链接时清理返回失败且不跟随
     *
     * @return void
     */
    public function testClearRejectsSymlinkedViewCacheDirectory(): void {
        $target=$this->dir.'/target_view';
        mkdir($target,0755,true);
        file_put_contents($target.'/stale.php','<?php return array();');
        if(!@symlink($target,$this->cache_dir)) {
            mkdir(dirname($this->cache_dir),0755,true);
            if(!@symlink($target,$this->cache_dir))
                $this->markTestSkipped('当前系统无法创建目录符号链接');
        }
        $cache=new FileCache($this->config('always'));
        $this->assertFalse($cache->clear(),'符号链接目录应返回失败');
        $this->assertTrue(is_file($target.'/stale.php'),'符号链接目标不得被清理');
        $this->assertTrue(is_link($this->cache_dir),'符号链接本身应保留');
        $this->assertFalse((new Cache($this->config('always')))->clearCompile('view'));
        $this->assertTrue(is_file($target.'/stale.php'));
    }

    /**
     * 测试视图缓存目录不存在时清理成功
     *
     * @return void
     */
    public function testClearMissingViewCacheDirectorySucceeds(): void {
        $cache=new FileCache($this->config('always'));
        $this->assertTrue($cache->clear());
        $this->assertTrue((new Cache($this->config('always')))->clearCompile('view'));
    }

    /**
     * 测试同秒同长度的源文件修改仍触发重建
     *
     * @return void
     */
    public function testSameSecondSameSizeChangeRebuilds(): void {
        $cache=new FileCache($this->config('always'));
        $compiler=new PhpCompiler();
        $this->assertSame('first A',$cache->load(new FileSource($this->template),$compiler)->render(array('name'=>'A')));

        $mtime=filemtime($this->template);
        file_put_contents($this->template,'third {{$name}}');
        touch($this->template,$mtime);
        clearstatcache(true,$this->template);

        $this->assertSame('third C',$cache->load(new FileSource($this->template),$compiler)->render(array('name'=>'C')));
    }

    /**
     * 测试缓存关闭时不写文件
     *
     * @return void
     */
    public function testDisabledCacheWritesNothing(): void {
        $cache=new FileCache($this->config('off'));
        $compiler=new PhpCompiler();
        $this->assertSame('first A',$cache->load(new FileSource($this->template),$compiler)->render(array('name'=>'A')));
        $this->assertFalse(is_dir($this->cache_dir));
    }

    /**
     * 测试编译产物与解释器输出一致
     *
     * @return void
     */
    public function testCompiledMatchesInterpreted(): void {
        file_put_contents($this->template,'{{foreach $items as $item}}{{if $item > 1}}{{$item}}{{/if}}{{/foreach}}');
        $data=array('items'=>array(1,2,3));
        $compiler=new PhpCompiler();
        $expected=$compiler->compile(new FileSource($this->template))->render($data);
        $cache=new FileCache($this->config('always'));
        $actual=$cache->load(new FileSource($this->template),$compiler)->render($data);
        $this->assertSame($expected,$actual);
        $this->assertSame('23',$actual);
    }

    /**
     * 测试构建命令会刷新已变化模板
     *
     * - `auto` + 非 debug 时读取路径零探测, 但 build() 必须重新编译
     *
     * @return void
     */
    public function testBuildRefreshesChangedTemplate(): void {
        $cache=new FileCache($this->config('auto'));
        $compiler=new PhpCompiler();
        $this->assertTrue($cache->build(new FileSource($this->template),$compiler));
        file_put_contents($this->template,'second {{$name}}');
        touch($this->template,time()+3);
        $this->assertTrue($cache->build(new FileSource($this->template),$compiler));
        $this->assertSame('second B',$cache->load(new FileSource($this->template),$compiler)->render(array('name'=>'B')));
    }

    /**
     * 测试非闭包缓存产物会被重建
     *
     * @return void
     */
    public function testNonClosurePayloadRebuilds(): void {
        $cache=new FileCache($this->config('always'));
        $compiler=new PhpCompiler();
        $cache->load(new FileSource($this->template),$compiler)->render(array('name'=>'A'));
        $files=scandir($this->cache_dir);
        $this->assertIsArray($files);
        foreach($files as $file) {
            if($file==='.'||$file==='..')
                continue;
            file_put_contents(
                $this->cache_dir.'/'.$file,
                '<?php return '.var_export(array(
                    'version'=>PhpCompiler::VERSION,
                    'fingerprint'=>'x',
                    'source'=>'x',
                    'compiled'=>'strlen'
                ),true).';'
            );
        }
        $this->assertSame('first A',$cache->load(new FileSource($this->template),$compiler)->render(array('name'=>'A')));
    }

    /**
     * 测试清理缓存
     *
     * @return void
     */
    public function testClear(): void {
        $cache=new FileCache($this->config('always'));
        $compiler=new PhpCompiler();
        $cache->load(new FileSource($this->template),$compiler)->render(array('name'=>'A'));
        $this->assertTrue($cache->clear());
        $this->assertFalse(is_dir($this->cache_dir));
    }

    /**
     * 测试损坏缓存会重建
     *
     * @return void
     */
    public function testCorruptedCacheRebuilds(): void {
        $cache=new FileCache($this->config('always'));
        $compiler=new PhpCompiler();
        $cache->load(new FileSource($this->template),$compiler)->render(array('name'=>'A'));
        $files=scandir($this->cache_dir);
        $this->assertIsArray($files);
        foreach($files as $file) {
            if($file==='.'||$file==='..')
                continue;
            file_put_contents($this->cache_dir.'/'.$file,'<?php return array("bad"=>true);');
        }
        $this->assertSame('first A',$cache->load(new FileSource($this->template),$compiler)->render(array('name'=>'A')));
    }

    /**
     * 测试源模板删除后产物标记为孤儿
     *
     * @return void
     */
    public function testInspectReportsOrphanedSource(): void {
        $cache=new FileCache($this->config('always'));
        $this->assertTrue($cache->build(new FileSource($this->template),new PhpCompiler()));
        unlink($this->template);
        $inspect=$cache->inspect();
        $this->assertSame('orphaned',$inspect['status']);
        $this->assertSame(1,$inspect['orphaned']);
        $this->assertStringContainsString('不可访问或已删除',$inspect['reason']);
    }

    /**
     * 测试清理会删除编译器版本不符的产物
     *
     * @return void
     */
    public function testPruneRemovesVersionMismatchedArtifact(): void {
        $cache=new FileCache($this->config('always'));
        $this->assertTrue($cache->build(new FileSource($this->template),new PhpCompiler()));
        $files=scandir($this->cache_dir);
        $this->assertIsArray($files);
        foreach($files as $file) {
            if($file==='.'||$file==='..')
                continue;
            $path=$this->cache_dir.'/'.$file;
            $content=file_get_contents($path);
            $this->assertIsString($content);
            file_put_contents($path,str_replace(
                "'version'=>'".PhpCompiler::VERSION."'",
                "'version'=>'0'",
                $content
            ));
        }
        $result=$cache->pruneMissingSources();
        $this->assertSame(1,$result['removed']);
        $this->assertSame('missing',$cache->inspect()['status']);
    }

    /**
     * 构造缓存配置
     *
     * @param string $mode 编译模式
     * @return Repository
     */
    private function config(string $mode): Repository {
        return new Repository(array(
            'app'=>array('debug'=>false),
            'cache'=>array(
                'path'=>$this->dir.'/cache',
                'compile'=>array(
                    'enabled'=>true,
                    'mode'=>$mode,
                    'view'=>true
                )
            )
        ));
    }

    /**
     * 递归删除
     *
     * @param string $path 路径
     * @return void
     */
    private function removePath(string $path): void {
        if(is_file($path)) {
            @unlink($path);
            return;
        }
        if(!is_dir($path))
            return;
        $entries=scandir($path);
        if($entries!==false) {
            foreach($entries as $entry) {
                if($entry==='.'||$entry==='..')
                    continue;
                $this->removePath($path.'/'.$entry);
            }
        }
        @rmdir($path);
    }

}

/**
 * 固定输出的自定义代码编译器
 */
class ViewCacheFixedCompiler implements CodeTemplateCompilerInterface {

    /**
     * 构造方法
     *
     * @param string $value 固定输出
     */
    public function __construct(private string $value) {
    }

    /**
     * 编译为解释产物
     *
     * @param TemplateSourceInterface $source 模板来源
     * @return CompiledTemplateInterface
     */
    public function compile(TemplateSourceInterface $source): CompiledTemplateInterface {
        $value=$this->value;
        return new class($value) implements CompiledTemplateInterface {
            public function __construct(private string $value) {
            }

            public function render(array $data): string {
                return $this->value;
            }
        };
    }

    /**
     * 生成 PHP 闭包代码
     *
     * @param TemplateSourceInterface $source 模板来源
     * @return string
     */
    public function code(TemplateSourceInterface $source): string {
        return 'static function(array $data): string { return '.var_export($this->value,true).'; }';
    }

}

/**
 * 另一个固定输出的自定义代码编译器
 */
final class ViewCacheOtherCompiler extends ViewCacheFixedCompiler {
}
