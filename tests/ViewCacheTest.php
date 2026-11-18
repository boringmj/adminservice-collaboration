<?php

namespace Tests;

use PHPUnit\Framework\TestCase;

use AdminService\Config\Repository;
use AdminService\View\Cache\FileCache;
use AdminService\View\Compiler\PhpCompiler;
use AdminService\View\Source\FileSource;

use function var_export;

use function count;
use function file_get_contents;
use function file_put_contents;
use function is_dir;
use function is_file;
use function mkdir;
use function rmdir;
use function scandir;
use function str_replace;
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
