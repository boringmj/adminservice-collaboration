<?php

namespace Tests;

use PHPUnit\Framework\TestCase;

use AdminService\App;
use AdminService\Application;
use AdminService\Cache;
use AdminService\Cache\CompileStore;
use AdminService\Config;
use AdminService\Config\Bootstrapper;
use AdminService\Config\Env;
use AdminService\Config\Repository;
use AdminService\Container;
use AdminService\Runtime;
use Tests\Fixtures\ConfigCacheAliasTarget;

use function AdminService\env;
use function AdminService\env_reset;
use function AdminService\env_snapshot;
use function class_exists;
use function clearstatcache;
use function file_put_contents;
use function getenv;
use function is_dir;
use function is_file;
use function is_link;
use function mkdir;
use function putenv;
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
 * - `Bootstrapper` 使用 `runtime/cache/compile/config.cache.php`
 */
class ConfigCacheTest extends TestCase {

    /**
     * 测试: 编译快照保留原始值与生效值
     *
     * @return void
     */
    public function testFromValidatedPreservesRawAndEffectiveValues(): void {
        $env=new Env("APP_DEBUG=false\n");
        $raw=array('app'=>array('debug'=>true,'timezone'=>'UTC'));
        $effective=$raw;
        $effective['app']['debug']=false;

        $config=Repository::fromValidated($raw,$effective,$env);

        $this->assertTrue($config->isValidated());
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
        $config=Repository::fromValidated($raw,$raw,new Env());
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
     * 测试: 存在 .env 时 Bootstrapper 默认关闭配置缓存且不读取旧快照
     *
     * @return void
     */
    public function testBootstrapperDisablesConfigCacheWhenEnvFilePresent(): void {
        $old_runtime=Runtime::path();
        $dir=$this->tempDir();
        try {
            Runtime::setPath($dir.'/runtime');
            $cache=$dir.'/runtime/cache/compile/config.cache.php';
            mkdir($dir.'/runtime/cache/compile',0755,true);
            file_put_contents($cache,'<?php return array();');

            $bootstrapper=new Bootstrapper();
            $config=$bootstrapper->loadConfig(new Env("APP_DEBUG=false\n",'/tmp/.env'));

            $this->assertInstanceOf(Repository::class,$config);
            $this->assertFalse($config->isValidated());
            $this->assertFileExists($cache,'存在 .env 时不读取旧配置快照，保留给显式清理入口');
        } finally {
            Runtime::setPath($old_runtime);
            $this->removePath($dir);
        }
    }

    /**
     * 测试: 环境快照存在时允许生成配置快照
     *
     * @return void
     */
    public function testBootstrapperAllowsConfigCacheWithCompiledEnv(): void {
        $old_runtime=Runtime::path();
        $dir=$this->tempDir();
        try {
            Runtime::setPath($dir.'/runtime');
            $env=Env::fromArray(array('APP_DEBUG'=>false),'/tmp/.env');

            $config=(new Bootstrapper())->loadConfig($env);

            $this->assertInstanceOf(Repository::class,$config);
            $this->assertTrue($config->isValidated(),'环境快照存在时应生成配置快照');
            $this->assertFileExists($dir.'/runtime/cache/compile/config.cache.php');
        } finally {
            Runtime::setPath($old_runtime);
            $this->removePath($dir);
        }
    }

    /**
     * 测试: 环境快照值变化时配置快照随之重建
     *
     * @return void
     */
    public function testConfigCacheRebuildsWhenEnvContextChanges(): void {
        $old_runtime=Runtime::path();
        $dir=$this->tempDir();
        try {
            Runtime::setPath($dir.'/runtime');
            $bootstrapper=new Bootstrapper();

            $first=$bootstrapper->loadConfig(Env::fromArray(
                array('APP_DEBUG'=>false,'app.timezone'=>'UTC'),
                '/tmp/.env'
            ));
            $this->assertSame('UTC',$first->get('app.timezone'));

            $second=$bootstrapper->loadConfig(Env::fromArray(
                array('APP_DEBUG'=>false,'app.timezone'=>'Asia/Tokyo'),
                '/tmp/.env'
            ));
            $this->assertTrue($second->isValidated());
            $this->assertSame('Asia/Tokyo',$second->get('app.timezone'),'env 上下文变化应重建配置快照');
        } finally {
            Runtime::setPath($old_runtime);
            $this->removePath($dir);
        }
    }

    /**
     * 测试: 环境快照重建后同步进程快照, 配置缓存使用新值
     *
     * @return void
     */
    public function testBuildEnvCacheSyncsProcessSnapshotForConfigBuild(): void {
        $old_runtime=Runtime::path();
        $old_env=getenv('ADMINSERVICE_TEST_ENV_FILE');
        $old_cache=getenv('ADMINSERVICE_TEST_ENV_CACHE');
        $dir=$this->tempDir();
        $env_file=$dir.'/.env';
        $env_cache=$dir.'/env.cache.php';
        $runtime=$dir.'/runtime';
        try {
            Runtime::setPath($runtime);
            putenv('ADMINSERVICE_TEST_ENV_FILE='.$env_file);
            putenv('ADMINSERVICE_TEST_ENV_CACHE='.$env_cache);
            file_put_contents($env_file,"APP_DEBUG=false\nDB_HOST=old-host\nDB_PASSWORD=old-pass\nRUNTIME_PATH=".$runtime."\n");
            env_reset();
            Runtime::reset();
            $bootstrapper=new Bootstrapper();
            $this->assertSame('built',$bootstrapper->buildEnvCache()['status']);

            // 模拟 CLI 已启动: 先加载旧快照
            env_reset();
            $old=env_snapshot();
            Runtime::setPath(Runtime::path($old));
            $this->assertSame('old-host',env('DB_HOST'));

            file_put_contents($env_file,"APP_DEBUG=false\nDB_HOST=new-host\nDB_PASSWORD=new-pass-longer\nRUNTIME_PATH=".$runtime."\n");
            $result=$bootstrapper->buildEnvCache();
            $this->assertSame('built',$result['status']);
            $this->assertSame('new-host',env('DB_HOST'),'构建后进程快照应切到新值');
            $this->assertSame(Runtime::path($result['env']),Runtime::path());

            $this->assertSame('built',$bootstrapper->buildConfigCache($result['env'],true)['status']);
            $data=include Runtime::path().'/cache/compile/config.cache.php';
            $this->assertSame('new-host',$data['payload']['raw']['database']['connections']['default']['host']);
            $this->assertSame('new-pass-longer',$data['payload']['raw']['database']['connections']['default']['password']);
        } finally {
            if($old_env===false)
                putenv('ADMINSERVICE_TEST_ENV_FILE');
            else
                putenv('ADMINSERVICE_TEST_ENV_FILE='.$old_env);
            if($old_cache===false)
                putenv('ADMINSERVICE_TEST_ENV_CACHE');
            else
                putenv('ADMINSERVICE_TEST_ENV_CACHE='.$old_cache);
            env_reset();
            Runtime::setPath($old_runtime);
            $this->removePath($dir);
        }
    }

    /**
     * 测试: RUNTIME_PATH 变更后缓存写入新 runtime
     *
     * @return void
     */
    public function testBuildEnvCacheMovesConfigCacheToNewRuntime(): void {
        $old_runtime=Runtime::path();
        $old_env=getenv('ADMINSERVICE_TEST_ENV_FILE');
        $old_cache=getenv('ADMINSERVICE_TEST_ENV_CACHE');
        $dir=$this->tempDir();
        $env_file=$dir.'/.env';
        $env_cache=$dir.'/env.cache.php';
        $runtime_old=$dir.'/runtime-old';
        $runtime_new=$dir.'/runtime-new';
        try {
            Runtime::setPath($runtime_old);
            putenv('ADMINSERVICE_TEST_ENV_FILE='.$env_file);
            putenv('ADMINSERVICE_TEST_ENV_CACHE='.$env_cache);
            file_put_contents($env_file,"APP_DEBUG=false\nRUNTIME_PATH=".$runtime_old."\n");
            env_reset();
            Runtime::reset();
            $bootstrapper=new Bootstrapper();
            $this->assertSame('built',$bootstrapper->buildEnvCache()['status']);

            // 模拟 CLI 已启动: 先加载旧快照
            env_reset();
            $old=env_snapshot();
            Runtime::setPath(Runtime::path($old));

            file_put_contents($env_file,"APP_DEBUG=false\nRUNTIME_PATH=".$runtime_new."\n");
            $result=$bootstrapper->buildEnvCache();
            $this->assertSame('built',$result['status']);
            $this->assertSame(Runtime::path($result['env']),Runtime::path());
            $this->assertStringEndsWith('/runtime-new',Runtime::path());

            $this->assertSame('built',$bootstrapper->buildConfigCache($result['env'],true)['status']);
            $this->assertFileExists(Runtime::path().'/cache/compile/config.cache.php');
        } finally {
            if($old_env===false)
                putenv('ADMINSERVICE_TEST_ENV_FILE');
            else
                putenv('ADMINSERVICE_TEST_ENV_FILE='.$old_env);
            if($old_cache===false)
                putenv('ADMINSERVICE_TEST_ENV_CACHE');
            else
                putenv('ADMINSERVICE_TEST_ENV_CACHE='.$old_cache);
            env_reset();
            Runtime::setPath($old_runtime);
            $this->removePath($dir);
        }
    }

    /**
     * 测试: Bootstrapper 在 debug + auto 下跳过配置缓存
     *
     * @return void
     */
    public function testBootstrapperSkipsConfigCacheInDebug(): void {
        $old_runtime=Runtime::path();
        $dir=$this->tempDir();
        try {
            Runtime::setPath($dir.'/runtime');
            $bootstrapper=new Bootstrapper();
            $config=$bootstrapper->loadConfig(new Env("APP_DEBUG=true\n"));

            $this->assertInstanceOf(Repository::class,$config);
            $this->assertTrue($config->isValidated());
            $this->assertFileDoesNotExist($dir.'/runtime/cache/compile/config.cache.php');
        } finally {
            Runtime::setPath($old_runtime);
            $this->removePath($dir);
        }
    }

    /**
     * 测试: Bootstrapper 从 runtime/cache/compile/config.cache.php 加载配置
     *
     * @return void
     */
    public function testBootstrapperLoadsConfigCache(): void {
        $old_runtime=Runtime::path();
        $dir=$this->tempDir();
        try {
            Runtime::setPath($dir.'/runtime');
            $bootstrapper=new Bootstrapper();
            $env=new Env("APP_DEBUG=false\n");

            $first=$bootstrapper->loadConfig($env);
            $this->assertInstanceOf(Repository::class,$first);
            $this->assertTrue($first->isValidated());
            $this->assertFileExists($dir.'/runtime/cache/compile/config.cache.php');

            $second=$bootstrapper->loadConfig($env);
            $this->assertInstanceOf(Repository::class,$second);
            $this->assertTrue($second->isValidated());
            $this->assertSame($first->get('app.timezone'),$second->get('app.timezone'));
        } finally {
            Runtime::setPath($old_runtime);
            $this->removePath($dir);
        }
    }

    /**
     * 测试: 自定义配置目录必须隔离配置缓存
     *
     * - 零探测模式不调用源指纹, 只能依赖上下文识别目录切换
     *
     * @return void
     */
    public function testBootstrapperConfigCacheSeparatesConfigDirs(): void {
        $old_runtime=Runtime::path();
        $base=$this->tempDir();
        $config_a=$base.'/config-a';
        $config_b=$base.'/config-b';
        mkdir($config_a,0755,true);
        mkdir($config_b,0755,true);
        file_put_contents($config_a.'/app.php',"<?php return array('name'=>'A','debug'=>false);\n");
        file_put_contents($config_b.'/app.php',"<?php return array('name'=>'B','debug'=>false);\n");
        try {
            Runtime::setPath($base.'/runtime');
            $env=new Env();
            $first=(new Bootstrapper($config_a))->loadConfig($env,false,true);
            $second=(new Bootstrapper($config_b))->loadConfig($env,false,true);

            $this->assertSame('A',$first->get('app.name'));
            $this->assertSame('B',$second->get('app.name'),'不同配置目录不能复用同一份零探测快照');
        } finally {
            Runtime::setPath($old_runtime);
            $this->removePath($base);
        }
    }

    /**
     * 测试: Bootstrapper 与 CompileStore 对字符串 false 的解析一致
     *
     * @return void
     */
    public function testBootstrapperTreatsQuotedFalseAsDisabled(): void {
        $env=new Env('CACHE_COMPILE="false"');
        $store=(new Bootstrapper())->configStore($env);

        $this->assertSame('off',$store->mode());
        $this->assertFalse($store->enabled('config'));
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
            $bootstrapper=new Bootstrapper();
            $env=new Env();

            $first=$bootstrapper->configFingerprint($store,$env,$config_dir);
            file_put_contents($file,"<?php return array('a'=>12345);");
            clearstatcache(true,$file);
            $second=$bootstrapper->configFingerprint($store,$env,$config_dir);

            $this->assertNotSame($first,$second);
        } finally {
            $this->removePath($dir);
        }
    }

    /**
     * 测试: 自定义 cache.path 时清理接口仍能清掉 runtime 配置快照
     *
     * @return void
     */
    public function testClearCompileCoversRuntimeConfigSnapshot(): void {
        $old_runtime=Runtime::path();
        $old_env_cache=getenv('ADMINSERVICE_TEST_ENV_CACHE');
        $dir=$this->tempDir();
        putenv('ADMINSERVICE_TEST_ENV_CACHE='.$dir.'/env.cache.php');
        try {
            Runtime::setPath($dir.'/runtime');
            $runtime_file=$dir.'/runtime/cache/compile/config.cache.php';
            mkdir(dirname($runtime_file),0755,true);
            $config=new Repository(array(
                'cache'=>array(
                    'path'=>$dir.'/custom-cache',
                    'compile'=>array('enabled'=>true)
                )
            ));
            $cache=new Cache($config);

            file_put_contents($runtime_file,'<?php return array();');
            $this->assertTrue($cache->clearCompile('config'));
            $this->assertFileDoesNotExist($runtime_file);

            file_put_contents($runtime_file,'<?php return array();');
            $this->assertTrue($cache->clearCompile());
            $this->assertFileDoesNotExist($runtime_file);

            file_put_contents($runtime_file,'<?php return array();');
            $this->assertTrue($cache->clearAll());
            $this->assertFileDoesNotExist($runtime_file);
        } finally {
            if($old_env_cache===false)
                putenv('ADMINSERVICE_TEST_ENV_CACHE');
            else
                putenv('ADMINSERVICE_TEST_ENV_CACHE='.$old_env_cache);
            Runtime::setPath($old_runtime);
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
