<?php

namespace Tests;

use PHPUnit\Framework\TestCase;

use AdminService\Cache\CompileStore;
use AdminService\Config\Bootstrapper;
use AdminService\Config\Env;
use AdminService\Config\Repository;
use AdminService\Console\Command\CacheBuildCommand;
use AdminService\Console\Command\CacheClearCommand;
use AdminService\Console\Command\CacheStatusCommand;
use AdminService\Console\Input;
use AdminService\Console\Output;
use AdminService\Router\RouterFactory;
use AdminService\Runtime;
use Tests\Fixtures\RouteCacheController;

use function file_get_contents;
use function file_put_contents;
use function filemtime;
use function is_dir;
use function is_file;
use function mkdir;
use function rmdir;
use function scandir;
use function sys_get_temp_dir;
use function time;
use function touch;
use function uniqid;

/**
 * 编译缓存命令测试
 */
class CacheCommandTest extends TestCase {

    /**
     * 临时目录
     * @var string
     */
    private string $dir;

    /**
     * 用例前的 Runtime 路径
     * @var string
     */
    private string $old_runtime;

    /**
     * 测试配置
     * @var Repository
     */
    private Repository $config;

    /**
     * 路由文件
     * @var string
     */
    private string $route_file;

    /**
     * 初始化隔离目录
     *
     * @return void
     */
    protected function setUp(): void {
        $this->dir=sys_get_temp_dir().'/adminservice_console_'.uniqid('',true);
        mkdir($this->dir,0755,true);
        $this->route_file=$this->dir.'/routes.php';
        file_put_contents($this->route_file,"<?php\n\nreturn function(\\AdminService\\Router\\Router \$router): void {\n"
            ."    \$router->get('/cli',array(\\Tests\\Fixtures\\RouteCacheController::class,'index'));\n};\n");
        $this->old_runtime=Runtime::path();
        Runtime::setPath($this->dir.'/runtime');
        $this->config=$this->makeConfig();
    }

    /**
     * 清理临时目录
     *
     * @return void
     */
    protected function tearDown(): void {
        Runtime::setPath($this->old_runtime);
        $this->removePath($this->dir);
    }

    /**
     * 构造配置
     *
     * @return Repository
     */
    private function makeConfig(): Repository {
        return new Repository(array(
            'app'=>array(
                'debug'=>false,
                'timezone'=>'Asia/Shanghai',
                'path'=>dirname(__DIR__).'/AdminService/app',
                'classes'=>array(RouteCacheController::class),
                'alias'=>array(),
                'param_cast'=>true
            ),
            'cache'=>array(
                'path'=>$this->dir.'/cache',
                'compile'=>array(
                    'enabled'=>true,
                    'mode'=>'always',
                    'config'=>true,
                    'route'=>true,
                    'container'=>true
                )
            ),
            'route'=>array(
                'files'=>array($this->route_file),
                'attributes'=>array('scan'=>false,'classes'=>array())
            ),
            'middlewares'=>array('controller'=>array(),'request'=>array()),
            'console'=>array(
                'default_command'=>'list',
                'commands'=>array(),
                'shortcuts'=>array()
            )
        ));
    }

    /**
     * 测试清理编译缓存不影响 kv
     *
     * @return void
     */
    public function testClearCompileKeepsKv(): void {
        $compile=$this->dir.'/cache/compile';
        $kv=$this->dir.'/cache/kv/default';
        mkdir($compile,0755,true);
        mkdir($kv,0755,true);
        file_put_contents($compile.'/config.cache.php','<?php return array();');
        file_put_contents($compile.'/routes.cache.php','<?php return array();');
        file_put_contents($kv.'/business.data.json','business');
        $command=new CacheClearCommand($this->config);
        $output=Output::buffer();
        $this->assertSame(0,$command->execute(new Input(array('routes')),$output));
        $this->assertFileDoesNotExist($compile.'/routes.cache.php');
        $this->assertFileExists($compile.'/config.cache.php');
        $this->assertFileExists($kv.'/business.data.json');

        $output=Output::buffer();
        $this->assertSame(0,$command->execute(new Input(array()),$output));
        $this->assertFileDoesNotExist($compile.'/config.cache.php');
        $this->assertFileExists($kv.'/business.data.json');
    }

    /**
     * 测试状态 missing / corrupted / valid / stale
     *
     * @return void
     */
    public function testCacheStatusStates(): void {
        $command=new CacheStatusCommand($this->config);
        $output=Output::buffer();
        $this->assertSame(0,$command->execute(new Input(array('routes')),$output));
        $this->assertStringContainsString('missing',$output->stdout());

        $compile=$this->dir.'/cache/compile';
        mkdir($compile,0755,true);
        file_put_contents($compile.'/routes.cache.php','<?php return array("bad" => true);');
        $output=Output::buffer();
        $this->assertSame(0,$command->execute(new Input(array('routes')),$output));
        $this->assertStringContainsString('corrupted',$output->stdout());

        file_put_contents($compile.'/routes.cache.php','<?php return array("fingerprint"=>"x","payload"=>array("version"=>1,"routes"=>array(),"sources"=>array()));');
        $store=new CompileStore($this->config);
        (new RouterFactory($store))->get($this->config);
        $output=Output::buffer();
        $this->assertSame(0,$command->execute(new Input(array('routes')),$output));
        $this->assertStringContainsString('valid',$output->stdout());

        file_put_contents($this->route_file,file_get_contents($this->route_file)."\n// changed");
        touch($this->route_file,time()+3);
        $output=Output::buffer();
        $this->assertSame(0,$command->execute(new Input(array('routes')),$output));
        $this->assertStringContainsString('stale',$output->stdout());
    }

    /**
     * 测试只构建容器时不隐式生成路由缓存
     *
     * @return void
     */
    public function testContainerBuildDoesNotCreateRouteCache(): void {
        $command=new CacheBuildCommand(new Bootstrapper(),$this->config);
        $output=Output::buffer();
        $this->assertSame(0,$command->execute(new Input(array('container','--force')),$output));
        $this->assertFileDoesNotExist($this->dir.'/cache/compile/routes.cache.php');
        $this->assertFileExists($this->dir.'/cache/compile/container.cache.php');
    }

    /**
     * 测试容器状态能识别类源文件变化
     *
     * @return void
     */
    public function testContainerStatusDetectsClassSourceChanges(): void {
        $command=new CacheBuildCommand(new Bootstrapper(),$this->config);
        $output=Output::buffer();
        $this->assertSame(0,$command->execute(new Input(array('container','--force')),$output));

        $file=(new \ReflectionClass(RouteCacheController::class))->getFileName();
        $this->assertIsString($file);
        $mtime=filemtime($file);
        $this->assertIsInt($mtime);
        try {
            touch($file,time()+5);
            $status=new CacheStatusCommand($this->config);
            $output=Output::buffer();
            $this->assertSame(0,$status->execute(new Input(array('container')),$output));
            $this->assertStringContainsString('stale',$output->stdout());
        } finally {
            touch($file,$mtime);
        }
    }

    /**
     * 测试非法缓存目标返回 2
     *
     * @return void
     */
    public function testCacheCommandsRejectInvalidTarget(): void {
        $commands=array(
            new CacheStatusCommand($this->config),
            new CacheClearCommand($this->config),
            new CacheBuildCommand(new Bootstrapper(),$this->config)
        );
        foreach($commands as $command) {
            $output=Output::buffer();
            $this->assertSame(2,$command->execute(new Input(array('bogus')),$output));
            $this->assertStringContainsString('Cache target is invalid',$output->stderr());

            $output=Output::buffer();
            $this->assertSame(2,$command->execute(new Input(array('--target=')),$output));
            $this->assertStringContainsString('Cache target requires a value',$output->stderr());
        }
    }

    /**
     * 测试路由与容器预热
     *
     * @return void
     */
    public function testCacheBuildRoutesAndContainer(): void {
        $command=new CacheBuildCommand(new Bootstrapper(),$this->config);
        $output=Output::buffer();
        $this->assertSame(0,$command->execute(new Input(array('routes','--force')),$output));
        $this->assertFileExists($this->dir.'/cache/compile/routes.cache.php');

        $output=Output::buffer();
        $this->assertSame(0,$command->execute(new Input(array('container','--force')),$output));
        $this->assertFileExists($this->dir.'/cache/compile/container.cache.php');
        $this->assertStringContainsString('container',$output->stdout());
    }

    /**
     * 测试 --force 在 debug + skip_on_debug 下仍能生成路由缓存
     *
     * @return void
     */
    public function testCacheBuildForceBypassesDebugSkip(): void {
        $configs=$this->config->all();
        $configs['app']['debug']=true;
        $configs['cache']['compile']['mode']='auto';
        $configs['cache']['compile']['skip_on_debug']=array('config','routes','container');
        $config=new Repository($configs);
        $command=new CacheBuildCommand(new Bootstrapper(),$config);
        $output=Output::buffer();
        $this->assertSame(0,$command->execute(new Input(array('routes','--force')),$output));
        $this->assertFileExists($this->dir.'/cache/compile/routes.cache.php');
        $this->assertStringContainsString('[已构建(BUILT)] routes',$output->stdout());
    }

    /**
     * 测试不可导出的路由处理器输出 SKIP
     *
     * @return void
     */
    public function testCacheBuildSkipsNonExportableRoutes(): void {
        file_put_contents($this->route_file,"<?php\n\nreturn function(\\AdminService\\Router\\Router \$router): void {\n"
            ."    \$router->get('/closure',function(): string { return 'x'; });\n};\n");
        $command=new CacheBuildCommand(new Bootstrapper(),$this->config);
        $output=Output::buffer();
        $this->assertSame(0,$command->execute(new Input(array('routes','--force')),$output));
        $this->assertStringContainsString('SKIP',$output->stdout());
    }

    /**
     * 测试存在 .env 时配置快照被跳过并清理
     *
     * @return void
     */
    public function testConfigBuildSkipsWhenEnvExists(): void {
        $compile=Runtime::path().'/cache/compile';
        mkdir($compile,0755,true);
        file_put_contents($compile.'/config.cache.php','<?php return array("old"=>true);');
        $env=new Env('APP_DEBUG=false', $this->dir.'/.env');
        $bootstrapper=new Bootstrapper();
        $result=$bootstrapper->buildConfigCache($env,true);
        $this->assertSame('skipped',$result['status']);
        $this->assertFileDoesNotExist($compile.'/config.cache.php');
    }

    /**
     * 测试无 .env 时生成配置快照
     *
     * @return void
     */
    public function testConfigBuildCreatesSnapshotWithoutEnv(): void {
        $env=new Env("APP_DEBUG=false\nCACHE_COMPILE_MODE=always");
        $bootstrapper=new Bootstrapper();
        $result=$bootstrapper->buildConfigCache($env,true);
        $this->assertSame('built',$result['status'],$result['reason']??'');
        $this->assertFileExists(Runtime::path().'/cache/compile/config.cache.php');
    }

    /**
     * 测试引导可跳过配置缓存写入
     *
     * @return void
     */
    public function testLoadConfigCanSkipConfigCache(): void {
        $env=new Env("APP_DEBUG=false\nCACHE_COMPILE_MODE=always");
        $bootstrapper=new Bootstrapper();
        $config=$bootstrapper->loadConfig($env,false,false);
        $this->assertIsString($config->get('app.timezone'));
        $this->assertFileDoesNotExist(Runtime::path().'/cache/compile/config.cache.php');
    }

    /**
     * 递归删除
     *
     * @param string $path 路径
     * @return void
     */
    private function removePath(string $path): void {
        if(is_file($path)||is_link($path)) {
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
