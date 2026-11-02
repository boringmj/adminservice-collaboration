<?php

namespace Tests;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;

use AdminService\App;
use AdminService\Application;
use AdminService\Cache\CompileStore;
use AdminService\Config;
use AdminService\Config\Env;
use AdminService\Config\Repository;
use AdminService\Container;
use AdminService\Main;
use AdminService\Runtime;
use Tests\Fixtures\ConfigCacheAliasTarget;

use function class_exists;
use function clearstatcache;
use function file_put_contents;
use function is_dir;
use function is_file;
use function is_link;
use function mkdir;
use function rmdir;
use function scandir;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

/**
 * 配置编译缓存测试
 *
 * - 编译快照保留原始配置与生效配置
 * - 编译配置跳过 `Application::init()` 的别名目标强加载
 * - `Main` 使用 `runtime/cache/compile/config.cache.php`
 */
class ConfigCacheTest extends TestCase {

    /**
     * 测试: 编译快照保留原始值与生效值
     *
     * @return void
     */
    public function testFromCompiledPreservesRawAndEffectiveValues(): void {
        $env=new Env("APP_DEBUG=false\n");
        $raw=array('app'=>array('debug'=>true,'timezone'=>'UTC'));
        $effective=$raw;
        $effective['app']['debug']=false;

        $config=Repository::fromCompiled($raw,$effective,$env);

        $this->assertTrue($config->isCompiled());
        $this->assertFalse($config->get('app.debug'));
        $this->assertTrue($config->config_raw('app.debug'));
        $this->assertSame('UTC',$config->get('app.timezone'));
        $this->assertFalse($config->env('APP_DEBUG'));
    }

    /**
     * 测试: 编译配置不会在 init 时强加载别名目标
     *
     * @return void
     */
    public function testCompiledConfigSkipsAliasAutoload(): void {
        $target=ConfigCacheAliasTarget::class;
        $this->assertFalse(class_exists($target,false),'前置条件: 目标类尚未加载');

        $raw=array('app'=>array(
            'param_cast'=>true,
            'classes'=>array(),
            'alias'=>array('cache.target'=>$target)
        ));
        $config=Repository::fromCompiled($raw,$raw,new Env());
        $old_config=Config::repository();
        $old_app=App::hasInstance()?App::getInstance():null;

        try {
            $application=new Application(new Container(),$config);
            $application->init();
            $this->assertFalse(class_exists($target,false),'编译配置不应强加载别名目标');
        } finally {
            if($old_app!==null)
                App::setInstance($old_app);
            if($old_config!==null)
                Config::setRepository($old_config);
        }
    }

    /**
     * 测试: 配置编译缓存命中与指纹失效
     *
     * @return void
     */
    public function testCompileStoreConfigPayloadRoundTrip(): void {
        $dir=$this->tempDir();
        try {
            $config=new Repository(array('cache'=>array(
                'path'=>$dir.'/cache',
                'dir_mode'=>0755,
                'compile'=>array(
                    'enabled'=>true,
                    'mode'=>'always',
                    'config'=>true
                )
            )));
            $store=new CompileStore($config);
            $calls=0;
            $builder=function() use (&$calls): array {
                $calls++;
                return array('validated'=>true,'raw'=>array('a'=>1),'effective'=>array('a'=>2));
            };

            $first=$store->load('config',fn(): string => 'fp1',$builder,'ctx');
            $second=$store->load('config',fn(): string => 'fp1',$builder,'ctx');
            $this->assertSame(1,$calls,'相同指纹与上下文应命中');
            $this->assertSame($first,$second);

            $third=$store->load('config',fn(): string => 'fp2',$builder,'ctx');
            $this->assertSame(2,$calls,'指纹变化应重建');
            $this->assertSame($first,$third);
            $this->assertFileExists($dir.'/cache/compile/config.cache.php');
        } finally {
            $this->removePath($dir);
        }
    }

    /**
     * 测试: debug + auto 默认跳过配置缓存, 但保留路由缓存
     *
     * @return void
     */
    public function testDebugAutoSkipsConfigButKeepsRouteCache(): void {
        $dir=$this->tempDir();
        try {
            $config=new Repository(array(
                'app'=>array('debug'=>true),
                'cache'=>array(
                    'path'=>$dir.'/cache',
                    'dir_mode'=>0755,
                    'compile'=>array(
                        'enabled'=>true,
                        'mode'=>'auto',
                        'config'=>true,
                        'route'=>true,
                        'container'=>true
                    )
                )
            ));
            $store=new CompileStore($config);
            $config_builds=0;
            $route_builds=0;

            $store->load('config',fn(): string => 'fp',function() use (&$config_builds): array {
                $config_builds++;
                return array('ok'=>true);
            },'ctx');
            $this->assertSame(1,$config_builds);
            $this->assertFileDoesNotExist($dir.'/cache/compile/config.cache.php','debug + auto 默认不写配置缓存');

            $store->load('routes',fn(): string => 'fp',function() use (&$route_builds): array {
                $route_builds++;
                return array('ok'=>true);
            },'ctx');
            $store->load('routes',fn(): string => 'fp',function() use (&$route_builds): array {
                $route_builds++;
                return array('ok'=>true);
            },'ctx');
            $this->assertSame(1,$route_builds,'路由缓存仍应在 debug 下命中');
            $this->assertFileExists($dir.'/cache/compile/routes.cache.php');
        } finally {
            $this->removePath($dir);
        }
    }

    /**
     * 测试: 显式 always 覆盖 debug 跳过策略
     *
     * @return void
     */
    public function testAlwaysOverridesDebugSkip(): void {
        $dir=$this->tempDir();
        try {
            $config=new Repository(array(
                'app'=>array('debug'=>true),
                'cache'=>array(
                    'path'=>$dir.'/cache',
                    'dir_mode'=>0755,
                    'compile'=>array(
                        'enabled'=>true,
                        'mode'=>'always',
                        'config'=>true,
                        'skip_on_debug'=>array('config')
                    )
                )
            ));
            $store=new CompileStore($config);
            $builds=0;
            $store->load('config',fn(): string => 'fp',function() use (&$builds): array {
                $builds++;
                return array('ok'=>true);
            },'ctx');

            $this->assertSame(1,$builds);
            $this->assertFileExists($dir.'/cache/compile/config.cache.php');
        } finally {
            $this->removePath($dir);
        }
    }

    /**
     * 测试: 存在 .env 时 Main 默认关闭配置缓存并清理旧快照
     *
     * @return void
     */
    public function testMainDisablesConfigCacheWhenEnvFilePresent(): void {
        $old_runtime=Runtime::path();
        $dir=$this->tempDir();
        try {
            Runtime::setPath($dir.'/runtime');
            $cache=$dir.'/runtime/cache/compile/config.cache.php';
            mkdir($dir.'/runtime/cache/compile',0755,true);
            file_put_contents($cache,'<?php return array();');

            $method=new ReflectionMethod(Main::class,'loadConfig');
            $method->setAccessible(true);
            $main=new Main();
            $config=$method->invoke($main,new Env("APP_DEBUG=false\n",'/tmp/.env'));

            $this->assertInstanceOf(Repository::class,$config);
            $this->assertFalse($config->isCompiled());
            $this->assertFileDoesNotExist($cache,'存在 .env 时应清理旧配置快照');
        } finally {
            Runtime::setPath($old_runtime);
            $this->removePath($dir);
        }
    }

    /**
     * 测试: Main 在 debug + auto 下跳过配置缓存
     *
     * @return void
     */
    public function testMainSkipsConfigCacheInDebug(): void {
        $old_runtime=Runtime::path();
        $dir=$this->tempDir();
        try {
            Runtime::setPath($dir.'/runtime');
            $method=new ReflectionMethod(Main::class,'loadConfig');
            $method->setAccessible(true);
            $main=new Main();
            $config=$method->invoke($main,new Env("APP_DEBUG=true\n"));

            $this->assertInstanceOf(Repository::class,$config);
            $this->assertTrue($config->isCompiled());
            $this->assertFileDoesNotExist($dir.'/runtime/cache/compile/config.cache.php');
        } finally {
            Runtime::setPath($old_runtime);
            $this->removePath($dir);
        }
    }

    /**
     * 测试: Main 从 runtime/cache/compile/config.cache.php 加载配置
     *
     * @return void
     */
    public function testMainLoadsConfigCache(): void {
        $old_runtime=Runtime::path();
        $dir=$this->tempDir();
        try {
            Runtime::setPath($dir.'/runtime');
            $method=new ReflectionMethod(Main::class,'loadConfig');
            $method->setAccessible(true);
            $main=new Main();
            $env=new Env("APP_DEBUG=false\n");

            $first=$method->invoke($main,$env);
            $this->assertInstanceOf(Repository::class,$first);
            $this->assertTrue($first->isCompiled());
            $this->assertFileExists($dir.'/runtime/cache/compile/config.cache.php');

            $second=$method->invoke($main,$env);
            $this->assertInstanceOf(Repository::class,$second);
            $this->assertTrue($second->isCompiled());
            $this->assertSame($first->get('app.timezone'),$second->get('app.timezone'));
        } finally {
            Runtime::setPath($old_runtime);
            $this->removePath($dir);
        }
    }

    /**
     * 测试: 配置源指纹能检测配置文件内容变化
     *
     * @return void
     */
    public function testConfigFingerprintDetectsFileChanges(): void {
        $dir=$this->tempDir();
        $config_dir=$dir.'/config';
        mkdir($config_dir,0755,true);
        $file=$config_dir.'/app.php';
        file_put_contents($file,"<?php return array('a'=>1);");
        clearstatcache(true,$file);
        try {
            $store=new CompileStore(new Repository(array(
                'cache'=>array(
                    'path'=>$dir.'/cache',
                    'compile'=>array('enabled'=>true)
                )
            )));
            $method=new ReflectionMethod(Main::class,'configFingerprint');
            $method->setAccessible(true);
            $main=new Main();
            $env=new Env();

            $first=$method->invoke($main,$store,$env,$config_dir);
            file_put_contents($file,"<?php return array('a'=>12345);");
            clearstatcache(true,$file);
            $second=$method->invoke($main,$store,$env,$config_dir);

            $this->assertNotSame($first,$second);
        } finally {
            $this->removePath($dir);
        }
    }

    /**
     * 创建临时目录
     *
     * @return string
     */
    private function tempDir(): string {
        $dir=sys_get_temp_dir().'/adminservice_config_cache_'.uniqid('',true);
        mkdir($dir,0755,true);
        return $dir;
    }

    /**
     * 递归删除临时目录
     *
     * @param string $path 文件或目录
     * @return void
     */
    private function removePath(string $path): void {
        if(is_link($path)||is_file($path)) {
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
