<?php

namespace Tests;

use PHPUnit\Framework\TestCase;

use AdminService\App;
use AdminService\Cache\EnvCache;
use AdminService\Config;
use AdminService\Config\Bootstrapper;
use AdminService\Config\Env;
use AdminService\Config\Repository;
use AdminService\Console;
use AdminService\Console\Output;
use AdminService\Database\DatabaseConfig;
use AdminService\Runtime;
use base\Database\Db as BaseDb;

use function AdminService\env_reset;
use function copy;
use function dirname;
use function file_put_contents;
use function getenv;
use function is_dir;
use function is_file;
use function mkdir;
use function putenv;
use function rmdir;
use function scandir;
use function str_ends_with;
use function str_replace;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;
use function var_export;

/**
 * cache status 经快捷绑定与默认命令进入时的配置缓存测试
 */
class ConsoleStatusCacheTest extends TestCase {

    /**
     * 临时工作区
     * @var string|null
     */
    private ?string $root=null;

    /**
     * 用例前的容器
     * @var mixed
     */
    private $old_container=null;

    /**
     * 用例前的配置
     * @var Repository|null
     */
    private ?Repository $old_config=null;

    /**
     * 用例前的测试环境文件
     * @var string|false
     */
    private string|false $old_env_file=false;

    /**
     * 用例前的环境快照路径
     * @var string|false
     */
    private string|false $old_env_cache=false;

    /**
     * 保存测试环境
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();
        $this->old_container=App::hasInstance()?App::getInstance():null;
        $this->old_config=Config::repository();
        $this->old_env_file=getenv('ADMINSERVICE_TEST_ENV_FILE');
        $this->old_env_cache=getenv('ADMINSERVICE_TEST_ENV_CACHE');
    }

    /**
     * 恢复测试环境并清理工作区
     *
     * @return void
     */
    protected function tearDown(): void {
        $this->restoreEnv('ADMINSERVICE_TEST_ENV_FILE',$this->old_env_file);
        $this->restoreEnv('ADMINSERVICE_TEST_ENV_CACHE',$this->old_env_cache);
        env_reset();
        Runtime::reset();
        BaseDb::setConfig(new DatabaseConfig());
        if($this->old_config!==null)
            Config::setRepository($this->old_config);
        if($this->old_container!==null)
            App::setInstance($this->old_container);
        if($this->root!==null) {
            $this->removePath($this->root);
            $this->root=null;
        }
        parent::tearDown();
    }

    /**
     * 测试: 快捷绑定进入 cache status 时不写配置缓存
     *
     * @return void
     */
    public function testStatusShortcutDoesNotWriteConfigCache(): void {
        $root=$this->workspace(array(
            'default_command'=>'list',
            'shortcuts'=>array('cs'=>'cache status')
        ));
        $output=Output::buffer();
        $console=new Console(null,$output,new Bootstrapper($root.'/config'));
        $code=$console->run(array('console','cs','config'));

        $this->assertSame(0,$code,$output->stderr());
        $this->assertStringContainsString('missing',$output->stdout());
        $this->assertFileDoesNotExist($root.'/runtime/cache/compile/config.cache.php','状态命令不得写配置缓存');
    }

    /**
     * 测试: 快捷绑定指向分组后再接 status 时不写配置缓存
     *
     * @return void
     */
    public function testStatusViaShortcutToGroupDoesNotWriteConfigCache(): void {
        $root=$this->workspace(array(
            'default_command'=>'list',
            'shortcuts'=>array('c'=>'cache')
        ));
        $output=Output::buffer();
        $console=new Console(null,$output,new Bootstrapper($root.'/config'));
        $code=$console->run(array('console','c','status','config'));

        $this->assertSame(0,$code,$output->stderr());
        $this->assertStringContainsString('missing',$output->stdout());
        $this->assertFileDoesNotExist($root.'/runtime/cache/compile/config.cache.php','状态命令不得写配置缓存');
    }

    /**
     * 测试: 默认命令进入 cache status 时不写配置缓存
     *
     * @return void
     */
    public function testDefaultStatusCommandDoesNotWriteConfigCache(): void {
        $root=$this->workspace(array(
            'default_command'=>'cache:status',
            'shortcuts'=>array()
        ));
        $output=Output::buffer();
        $console=new Console(null,$output,new Bootstrapper($root.'/config'));
        $code=$console->run(array('console'));

        $this->assertSame(0,$code,$output->stderr());
        $this->assertStringContainsString('missing',$output->stdout());
        $this->assertFileDoesNotExist($root.'/runtime/cache/compile/config.cache.php','状态命令不得写配置缓存');
    }

    /**
     * 测试: 非状态命令仍会生成配置缓存
     *
     * @return void
     */
    public function testOtherCommandsStillWriteConfigCache(): void {
        $root=$this->workspace(array(
            'default_command'=>'list',
            'shortcuts'=>array('cs'=>'cache status')
        ));
        $output=Output::buffer();
        $console=new Console(null,$output,new Bootstrapper($root.'/config'));
        $code=$console->run(array('console','list'));

        $this->assertSame(0,$code,$output->stderr());
        $this->assertFileExists($root.'/runtime/cache/compile/config.cache.php');
    }

    /**
     * 创建隔离工作区并启用配置缓存
     *
     * @param array{default_command:string,shortcuts:array<string,string>} $console console 配置
     * @return string 工作区根目录
     */
    private function workspace(array $console): string {
        $root=str_replace('\\','/',sys_get_temp_dir()).'/adminservice_status_'.uniqid('',true);
        $this->root=$root;
        $source=str_replace('\\','/',dirname(__DIR__)).'/AdminService';
        foreach(array('config','app','view','routes') as $dir)
            if(!mkdir($root.'/'.$dir,0755,true))
                $this->fail('Unable to create workspace directory: '.$dir);
        foreach(scandir($source.'/config') as $file)
            if(str_ends_with($file,'.php'))
                copy($source.'/config/'.$file,$root.'/config/'.$file);
        copy($source.'/view/error.html',$root.'/view/error.html');
        // 测试进程已加载真实函数库, 隔离目录不重复加载
        file_put_contents($root.'/config/function.php',"<?php\n\nreturn array(\n"
            ."    'path'=>".var_export($source.'/common',true).",\n"
            ."    'loader'=>array()\n"
            .");\n");
        file_put_contents($root.'/config/console.php',"<?php\n\nreturn array(\n"
            ."    'default_command'=>".var_export($console['default_command'],true).",\n"
            ."    'commands'=>array(),\n"
            ."    'shortcuts'=>".var_export($console['shortcuts'],true).",\n"
            ."    'serve'=>array('host'=>'localhost','port'=>8000,'docroot'=>".var_export($source.'/../public',true).")\n"
            .");\n");
        $env_file=$root.'/env';
        file_put_contents($env_file,"APP_DEBUG=false\n"
            ."CACHE_COMPILE=true\n"
            ."CACHE_COMPILE_MODE=always\n"
            ."RUNTIME_PATH=".$root."/runtime\n");
        putenv('ADMINSERVICE_TEST_ENV_FILE='.$env_file);
        putenv('ADMINSERVICE_TEST_ENV_CACHE='.$root.'/env.cache.php');
        env_reset();
        Runtime::reset();
        $env=Env::fromFile($env_file);
        $this->assertTrue(EnvCache::write($env_file,$env),'环境快照应写入成功');
        return $root;
    }

    /**
     * 恢复环境变量
     *
     * @param string $name 变量名
     * @param string|false $value 原值(false = 原不存在)
     * @return void
     */
    private function restoreEnv(string $name,string|false $value): void {
        if($value===false)
            putenv($name);
        else
            putenv($name.'='.$value);
    }

    /**
     * 递归删除目录
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
