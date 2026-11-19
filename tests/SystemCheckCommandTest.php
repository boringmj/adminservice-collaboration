<?php

namespace Tests;

use PHPUnit\Framework\TestCase;

use AdminService\Cache\EnvCache;
use AdminService\Config\Bootstrapper;
use AdminService\Config\Env;
use AdminService\Config\Repository;
use AdminService\Console\Command\SystemCheckCommand;
use AdminService\Console\Input;
use AdminService\Console\Output;
use AdminService\Runtime;
use AdminService\View\Cache\FileCache;
use AdminService\View\Compiler\PhpCompiler;
use AdminService\View\Source\FileSource;

use function AdminService\env_reset;
use function array_column;
use function count;
use function file_put_contents;
use function getenv;
use function is_array;
use function is_dir;
use function is_file;
use function json_decode;
use function mkdir;
use function putenv;
use function rmdir;
use function scandir;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

/**
 * system check 命令测试
 */
class SystemCheckCommandTest extends TestCase {

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
     * 初始化隔离目录
     *
     * @return void
     */
    protected function setUp(): void {
        $this->dir=sys_get_temp_dir().'/adminservice_system_check_'.uniqid('',true);
        mkdir($this->dir,0755,true);
        $this->old_runtime=Runtime::path();
        $this->old_env_file=getenv('ADMINSERVICE_TEST_ENV_FILE');
        $this->old_env_cache=getenv('ADMINSERVICE_TEST_ENV_CACHE');
        Runtime::setPath($this->dir.'/runtime');
        putenv('ADMINSERVICE_TEST_ENV_FILE='.$this->envFile());
        putenv('ADMINSERVICE_TEST_ENV_CACHE='.$this->dir.'/env.cache.php');
        env_reset();
    }

    /**
     * 清理临时目录
     *
     * @return void
     */
    protected function tearDown(): void {
        $this->restoreEnv('ADMINSERVICE_TEST_ENV_FILE',$this->old_env_file);
        $this->restoreEnv('ADMINSERVICE_TEST_ENV_CACHE',$this->old_env_cache);
        env_reset();
        Runtime::setPath($this->old_runtime);
        $this->removePath($this->dir);
    }

    /**
     * 测试: JSON 输出包含检查项与汇总
     *
     * @return void
     */
    public function testJsonOutputContainsChecksAndSummary(): void {
        [$command]=$this->command();
        $output=Output::buffer();
        $code=$command->execute(new Input(array('--no-db','--json')),$output);

        $this->assertSame(1,$code,$output->stderr());
        $data=json_decode($output->stdout(),true);
        $this->assertIsArray($data);
        $this->assertArrayHasKey('checks',$data);
        $this->assertArrayHasKey('summary',$data);
        $this->assertNotSame(array(),$data['checks']);
        $names=array_column($data['checks'],'name');
        $this->assertContains('Opcache',$names);
        $this->assertContains('PHP 版本',$names);
        $this->assertContains('数据库',$names);
        $total=0;
        foreach($data['summary'] as $count)
            $total+=$count;
        $this->assertSame(count($data['checks']),$total);
    }

    /**
     * 测试: --no-db 跳过数据库检查
     *
     * @return void
     */
    public function testNoDbSkipsDatabaseCheck(): void {
        [$command]=$this->command();
        $output=Output::buffer();
        $command->execute(new Input(array('--no-db','--json')),$output);
        $data=json_decode($output->stdout(),true);
        $this->assertIsArray($data);
        $database=null;
        foreach($data['checks'] as $check)
            if($check['name']==='数据库')
                $database=$check;
        $this->assertIsArray($database);
        $this->assertSame('skip',$database['status']);
    }

    /**
     * 测试: 多余位置参数报错返回 2
     *
     * @return void
     */
    public function testSystemCheckRejectsPositionalArguments(): void {
        [$command]=$this->command();
        $output=Output::buffer();
        $code=$command->execute(new Input(array('foo','--no-db')),$output);

        $this->assertSame(2,$code);
        $this->assertStringContainsString('多余位置参数',$output->stderr());
        $this->assertStringContainsString('foo',$output->stderr());
    }

    /**
     * 测试: 降级模式区分"配置不可用"与"未配置数据库"
     *
     * @return void
     */
    public function testDegradedModeReportsUnavailableConfigForDatabase(): void {
        [$command]=$this->command(array(),array('helper'),'配置文件语法错误');
        $output=Output::buffer();
        $code=$command->execute(new Input(array('--json')),$output);

        $this->assertSame(1,$code,$output->stderr());
        $data=json_decode($output->stdout(),true);
        $this->assertIsArray($data);
        $database=$this->checkNamed($data['checks'],'数据库');
        $this->assertSame('skip',$database['status']);
        $this->assertSame('配置不可用',$database['detail']);
    }

    /**
     * 测试: JSON 编码失败报错而不是输出空对象
     *
     * - `log.path` 指向含非法 UTF-8 字节的路径, 检查详情无法编码
     *
     * @return void
     */
    public function testJsonEncodeFailureReportsErrorInsteadOfEmptyObject(): void {
        [$command]=$this->command(
            array(),
            array('helper'),
            null,
            array('log'=>array('path'=>"\xB1\x31"))
        );
        $output=Output::buffer();
        $code=$command->execute(new Input(array('--no-db','--json')),$output);

        $this->assertSame(1,$code);
        $this->assertStringContainsString('JSON',$output->stderr());
        $this->assertSame('',$output->stdout(),'编码失败不应输出空对象');
    }

    /**
     * 测试: 按配置的连接数量逐个检查
     *
     * @return void
     */
    public function testDatabaseChecksEveryConfiguredConnection(): void {
        [$command]=$this->command(array('first'=>array(),'second'=>array()));
        $output=Output::buffer();
        $code=$command->execute(new Input(array('--json')),$output);

        $this->assertSame(1,$code,$output->stderr());
        $data=json_decode($output->stdout(),true);
        $this->assertIsArray($data);
        $names=array_column($data['checks'],'name');
        $this->assertContains('数据库(first)',$names);
        $this->assertContains('数据库(second)',$names);
    }

    /**
     * 测试: plain 形式不输出表格边框
     *
     * @return void
     */
    public function testPlainFormatHasNoTableBorders(): void {
        [$command]=$this->command();
        $output=Output::buffer();
        $command->execute(new Input(array('--no-db','--format=plain')),$output);

        $stdout=$output->stdout();
        $this->assertStringContainsString('[OK] PHP 版本: ',$stdout);
        $this->assertStringNotContainsString('+---',$stdout);
    }

    /**
     * 测试: compact 只输出 WARN 与 FAIL
     *
     * @return void
     */
    public function testCompactHidesOkChecks(): void {
        [$command]=$this->command();
        $output=Output::buffer();
        $command->execute(new Input(array('--no-db','--format=plain','--compact')),$output);

        $stdout=$output->stdout();
        $this->assertStringNotContainsString('[OK]',$stdout);
        $this->assertStringContainsString('[WARN]',$stdout);
        $this->assertStringContainsString('汇总(Summary)',$stdout);
    }

    /**
     * 测试: 非法输出形式返回 2
     *
     * @return void
     */
    public function testInvalidFormatReturnsInvalid(): void {
        [$command]=$this->command();
        $output=Output::buffer();
        $this->assertSame(2,$command->execute(new Input(array('--format=xml')),$output));
        $this->assertStringContainsString('输出形式无效',$output->stderr());
    }

    /**
     * 测试: --format 优先于 --json
     *
     * @return void
     */
    public function testFormatTakesPrecedenceOverJson(): void {
        [$command]=$this->command();
        $output=Output::buffer();
        $command->execute(new Input(array('--no-db','--json','--format=plain')),$output);

        $this->assertStringContainsString('[OK] PHP 版本: ',$output->stdout());
        $this->assertStringNotContainsString('"checks"',$output->stdout());
    }

    /**
     * 测试: 函数文件齐全时不报警
     *
     * @return void
     */
    public function testFunctionFilesPassWhenPresent(): void {
        [$command]=$this->command();
        $output=Output::buffer();
        $command->execute(new Input(array('--no-db','--json')),$output);
        $data=json_decode($output->stdout(),true);
        $this->assertIsArray($data);
        $check=$this->checkNamed($data['checks'],'函数文件');
        $this->assertSame('ok',$check['status'],$check['detail']);
        $this->assertSame('1 个文件',$check['detail']);
    }

    /**
     * 测试: 缺失函数文件报告失败
     *
     * @return void
     */
    public function testMissingFunctionFileIsReported(): void {
        [$command]=$this->command(array(),array('helper','missing_helper'));
        $output=Output::buffer();
        $code=$command->execute(new Input(array('--no-db','--json')),$output);
        $this->assertSame(1,$code,$output->stderr());
        $data=json_decode($output->stdout(),true);
        $this->assertIsArray($data);
        $check=$this->checkNamed($data['checks'],'函数文件');
        $this->assertSame('fail',$check['status']);
        $this->assertSame('缺失或不可读: missing_helper',$check['detail']);
        $this->assertStringContainsString('function.loader',$check['suggestion']);
    }

    /**
     * 测试: 视图缓存源文件删除时报告警告
     *
     * @return void
     */
    public function testOrphanedViewCacheIsReported(): void {
        [$command,,,$config]=$this->command();
        $template=$this->dir.'/orphan.html';
        file_put_contents($template,'x');
        $cache=new FileCache($config);
        $this->assertTrue($cache->build(new FileSource($template),new PhpCompiler()));
        unlink($template);

        $output=Output::buffer();
        $command->execute(new Input(array('--no-db','--json')),$output);
        $data=json_decode($output->stdout(),true);
        $this->assertIsArray($data);
        $check=$this->checkNamed($data['checks'],'视图缓存');
        $this->assertSame('warn',$check['status']);
        $this->assertStringContainsString('不可访问或已删除',$check['detail']);
    }

    /**
     * 测试: 非法超时返回 2
     *
     * @return void
     */
    public function testInvalidTimeoutReturnsInvalid(): void {
        [$command]=$this->command();
        $output=Output::buffer();
        $this->assertSame(2,$command->execute(new Input(array('--timeout=0')),$output));
        $this->assertStringContainsString('超时',$output->stderr());
    }

    /**
     * 构造命令与配置
     *
     * @param array<string,array<string,mixed>> $connections 数据库连接
     * @param array<int,string> $function_loader 函数文件
     * @param string|null $boot_error 引导失败原因(null = 正常启动)
     * @param array<string,mixed> $overrides 顶层配置覆盖
     * @return array{0:SystemCheckCommand,1:Bootstrapper,2:Env,3:Repository}
     */
    private function command(
        array $connections=array(),
        array $function_loader=array('helper'),
        ?string $boot_error=null,
        array $overrides=array()
    ): array {
        $app=$this->dir.'/app';
        $view=$this->dir.'/view';
        if(!is_dir($app))
            mkdir($app,0755,true);
        if(!is_dir($view))
            mkdir($view,0755,true);
        file_put_contents($view.'/error.html','error');
        $common=$this->dir.'/common';
        if(!is_dir($common))
            mkdir($common,0755,true);
        file_put_contents($common.'/helper.php','<?php');
        $config_dir=$this->dir.'/config';
        if(!is_dir($config_dir))
            mkdir($config_dir,0755,true);
        file_put_contents($config_dir.'/app.php',"<?php\n\nreturn array('debug'=>false);\n");

        $config=new Repository(array_merge(array(
            'function'=>array(
                'path'=>$common,
                'loader'=>$function_loader
            ),
            'app'=>array(
                'debug'=>false,
                'path'=>$app,
                'error_template'=>$view.'/error.html'
            ),
            'cache'=>array(
                'path'=>$this->dir.'/cache',
                'dir_mode'=>0755,
                'default_pool'=>'default',
                'compile'=>array(
                    'enabled'=>true,
                    'mode'=>'always',
                    'config'=>true,
                    'route'=>true,
                    'container'=>true,
                    'view'=>true
                )
            ),
            'log'=>array('path'=>$this->dir.'/log'),
            'database'=>array('connections'=>$connections),
            'view'=>array('allowed_paths'=>array())
        ),$overrides));
        $bootstrapper=new Bootstrapper($config_dir);
        $env=Env::fromFile($this->envFile());
        $this->assertTrue(EnvCache::write($this->envFile(),$env),'环境快照应写入成功');
        env_reset();
        return array(new SystemCheckCommand($config,$bootstrapper,$boot_error),$bootstrapper,$env,$config);
    }

    /**
     * 查找指定检查项
     *
     * @param array<int,array{name:string,status:string,detail:string,suggestion:string}> $checks 检查结果
     * @param string $name 检查名
     * @return array{name:string,status:string,detail:string,suggestion:string}
     */
    private function checkNamed(array $checks,string $name): array {
        foreach($checks as $check)
            if($check['name']===$name)
                return $check;
        $this->fail('缺少检查项: '.$name);
    }

    /**
     * 环境文件路径
     *
     * @return string
     */
    private function envFile(): string {
        $file=$this->dir.'/env';
        if(!is_file($file))
            file_put_contents($file,"RUNTIME_PATH=".$this->dir."/runtime\n");
        return $file;
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
