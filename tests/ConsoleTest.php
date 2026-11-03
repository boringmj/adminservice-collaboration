<?php

namespace Tests;

use PHPUnit\Framework\TestCase;

use AdminService\App;
use AdminService\Application;
use AdminService\Config;
use AdminService\Config\Repository;
use AdminService\Console;
use AdminService\Console\CommandRegistry;
use AdminService\Console\Input;
use AdminService\Console\Output;
use AdminService\Runtime;
use base\AbstractCommand;
use InvalidArgumentException;
use Tests\Fixtures\command\FailCommand;
use Tests\Fixtures\command\GreetCommand;

use function array_slice;
use function file_get_contents;
use function implode;
use function is_file;
use function is_array;
use function proc_close;
use function proc_open;
use function stream_get_contents;

/**
 * 命令行基础设施与入口测试
 */
class ConsoleTest extends TestCase {

    /**
     * 测试前的容器
     * @var mixed
     */
    private $old_container=null;

    /**
     * 测试前的配置
     * @var Repository|null
     */
    private ?Repository $old_config=null;

    /**
     * 测试开始时配置缓存是否已存在
     * @var bool
     */
    private bool $config_cache_existed=false;

    /**
     * 配置缓存路径
     * @var string
     */
    private string $config_cache_path='';

    /**
     * 保存测试环境
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();
        $this->old_container=App::hasInstance()?App::getInstance():null;
        $this->old_config=Config::repository();
        $this->config_cache_path=Runtime::path().'/cache/compile/config.cache.php';
        $this->config_cache_existed=is_file($this->config_cache_path);
    }

    /**
     * 恢复测试环境
     *
     * @return void
     */
    protected function tearDown(): void {
        if(!$this->config_cache_existed&&is_file($this->config_cache_path))
            @unlink($this->config_cache_path);
        if($this->old_config!==null)
            Config::setRepository($this->old_config);
        if($this->old_container!==null)
            App::setInstance($this->old_container);
        parent::tearDown();
    }

    /**
     * 创建测试应用
     *
     * @param array<string,mixed> $console console 配置覆盖
     * @return Application
     */
    private function application(array $console=array()): Application {
        $configs=Config::all();
        $configs['app']['debug']=false;
        $configs['console']['default_command']='list';
        $configs['console']['commands']=array();
        $configs['console']['shortcuts']=array();
        foreach($console as $key=>$value)
            $configs['console'][$key]=$value;
        $config=$configs=Config::new($configs);
        return (new Application(null,$config))->init();
    }

    /**
     * 测试命令注册表最长路径、别名、快捷绑定与冒号写法
     *
     * @return void
     */
    public function testRegistryResolvesAliasesShortcutsAndColonSyntax(): void {
        $registry=new CommandRegistry();
        $registry->register($this->serverCommand());
        $registry->register($this->cacheCommand());
        $registry->shortcut('start','server start');
        $expected=array(
            array('server','start'),
            array('start'),
            array('serve'),
            array('server:start')
        );
        foreach($expected as $tokens) {
            $result=$registry->resolve($tokens);
            $this->assertSame('command',$result['type']);
            $this->assertSame('server start',$result['name']);
        }
        $result=$registry->resolve(array('cache:build','--force'));
        $this->assertSame('cache build',$result['name']);
        $this->assertSame(array('--force'),$result['remaining']);
        $this->assertSame(1,$result['consumed']);
        $group=$registry->resolve(array('server'));
        $this->assertSame('group',$group['type']);
        $this->assertSame('server',$group['name']);
    }

    /**
     * 测试快捷绑定指向分组后仍可继续解析子命令
     *
     * @return void
     */
    public function testRegistryShortcutToGroupResolvesChildCommand(): void {
        $registry=new CommandRegistry();
        $registry->register($this->serverCommand());
        $registry->register($this->cacheCommand());
        $registry->shortcut('c','cache');
        $result=$registry->resolve(array('c','build','--force'));
        $this->assertSame('command',$result['type']);
        $this->assertSame('cache build',$result['name']);
        $this->assertSame(2,$result['consumed']);
        $this->assertSame(array('--force'),$result['remaining']);
        $group=$registry->resolve(array('c'));
        $this->assertSame('group',$group['type']);
        $this->assertSame('cache',$group['name']);
    }

    /**
     * 测试别名与快捷绑定冲突
     *
     * @return void
     */
    public function testRegistryRejectsConflicts(): void {
        $registry=new CommandRegistry();
        $registry->register($this->serverCommand());
        $this->expectException(\AdminService\Exception::class);
        $registry->shortcut('server','server start');
        $registry->finalize();
    }

    /**
     * 测试输入选项解析和 `--` 透传
     *
     * @return void
     */
    public function testInputParsesValuesAndDoesNotSwallowBooleanArguments(): void {
        $input=new Input(array('--port=8000','--force','build','--','-x','tail'),array('port','p'));
        $this->assertSame('8000',$input->option('port'));
        $this->assertTrue($input->option('force'));
        $this->assertSame(array('build','-x','tail'),$input->arguments());
        $this->assertSame(array('-x','tail'),$input->passthrough());

        $input=new Input(array('-p','8000','--port','8001'),array('port','p'));
        $this->assertSame('8001',$input->option('port','p'));
        $input=new Input(array('-p=8000'),array('port','p'));
        $this->assertSame('8000',$input->option('port','p'));
        $input=new Input(array('--name',''),array('name'));
        $this->assertSame('',$input->option('name'));
        $input=new Input(array('--name='),array('name'));
        $this->assertSame('',$input->option('name'));
    }

    /**
     * 测试选项缺值
     *
     * @return void
     */
    public function testInputRejectsMissingValue(): void {
        $this->expectException(InvalidArgumentException::class);
        new Input(array('--port'),array('port'));
    }

    /**
     * 测试短选项不支持粘连值
     *
     * @return void
     */
    public function testInputRejectsAttachedShortValue(): void {
        $this->expectException(InvalidArgumentException::class);
        new Input(array('-p8000'),array('port','p'));
    }

    /**
     * 测试自定义命令构造注入、属性注入与参数解析
     *
     * @return void
     */
    public function testConsoleRunsCustomCommand(): void {
        $app=$this->application(array(
            'commands'=>array(GreetCommand::class)
        ));
        $output=Output::buffer();
        $console=new Console($app,$output);
        $code=$console->run(array('console','demo','greet','--name=OpenCode'));
        $this->assertSame(0,$code);
        $this->assertStringContainsString('Hello OpenCode',$output->stdout());
        $this->assertStringContainsString('timezone=Asia/Shanghai',$output->stdout());
        $this->assertStringContainsString('default=list',$output->stdout());
    }

    /**
     * 测试命令异常只写 STDERR, verbose 才输出堆栈
     *
     * @return void
     */
    public function testConsoleErrorExitAndVerbose(): void {
        $app=$this->application(array(
            'commands'=>array(FailCommand::class)
        ));
        $output=Output::buffer();
        $console=new Console($app,$output);
        $code=$console->run(array('console','--verbose','demo','fail'));
        $this->assertSame(1,$code);
        $this->assertStringContainsString('command boom',$output->stderr());
        $this->assertStringContainsString('RuntimeException',$output->stderr());
        $this->assertStringNotContainsString('<html',$output->stdout().$output->stderr());
    }

    /**
     * 测试未知选项返回 2
     *
     * @return void
     */
    public function testConsoleRejectsUnknownOption(): void {
        $app=$this->application();
        $output=Output::buffer();
        $console=new Console($app,$output);
        $this->assertSame(2,$console->run(array('console','server','start','--unknown')));
        $this->assertStringContainsString('Unknown option',$output->stderr());
        $this->assertStringNotContainsString('<html',$output->stdout().$output->stderr());
    }

    /**
     * 测试未知命令与组帮助
     *
     * @return void
     */
    public function testConsoleUnknownAndGroupHelp(): void {
        $app=$this->application();
        $output=Output::buffer();
        $console=new Console($app,$output);
        $this->assertSame(2,$console->run(array('console','unknown')));
        $this->assertStringContainsString('Unknown command',$output->stderr());

        $output=Output::buffer();
        $console=new Console($app,$output);
        $this->assertSame(0,$console->run(array('console','cache')));
        $this->assertStringContainsString('分组(Group): cache',$output->stdout());
        $this->assertStringContainsString('cache build',$output->stdout());

        $output=Output::buffer();
        $console=new Console($app,$output);
        $this->assertSame(2,$console->run(array('console','cache','--bogus')));
        $this->assertStringContainsString('Unknown option',$output->stderr());
    }

    /**
     * 测试无 bin 启动器的自定义分组使用 console 形式帮助
     *
     * @return void
     */
    public function testHelpUsesConsoleForCustomGroup(): void {
        $app=$this->application(array(
            'commands'=>array(GreetCommand::class)
        ));
        $output=Output::buffer();
        $console=new Console($app,$output);
        $this->assertSame(0,$console->run(array('console','help','demo')));
        $this->assertStringContainsString('用法(Usage): php console demo [arguments] [options]',$output->stdout());
        $this->assertStringNotContainsString('php bin/demo',$output->stdout());
    }

    /**
     * 测试 server start 的 dry-run 与参数形式
     *
     * @return void
     */
    public function testServerStartDryRun(): void {
        $app=$this->application();
        $output=Output::buffer();
        $console=new Console($app,$output);
        $code=$console->run(array('console','server:start','--dry-run','-p=8012','--docroot='.dirname(__DIR__).'/public'));
        $this->assertSame(0,$code);
        $this->assertStringContainsString('8012',$output->stdout());
        $this->assertStringContainsString('Dry run',$output->stdout());
        $this->assertStringContainsString('-S',$output->stdout());
    }

    /**
     * 测试默认命令为 list
     *
     * @return void
     */
    public function testConsoleDefaultCommand(): void {
        $app=$this->application();
        $output=Output::buffer();
        $console=new Console($app,$output);
        $this->assertSame(0,$console->run(array('console')));
        $this->assertStringContainsString('AdminService Console',$output->stdout());
        $this->assertStringContainsString('用法(Usage):',$output->stdout());
        $this->assertStringContainsString('命令(Commands):',$output->stdout());
        $this->assertStringContainsString('命令(Command)',$output->stdout());
        $this->assertStringContainsString('cache status',$output->stdout());
    }

    /**
     * 子进程运行命令
     *
     * @param array<int,string> $command 命令数组
     * @param string $cwd 工作目录
     * @return array{code:int,stdout:string,stderr:string}
     */
    private function process(array $command,string $cwd): array {
        $descriptors=array(
            0=>array('pipe','r'),
            1=>array('pipe','w'),
            2=>array('pipe','w')
        );
        $pipes=array();
        $process=proc_open($command,$descriptors,$pipes,$cwd);
        if(!is_resource($process))
            $this->fail('Unable to start subprocess.');
        fclose($pipes[0]);
        $stdout=stream_get_contents($pipes[1]);
        $stderr=stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code=proc_close($process);
        return array(
            'code'=>$code,
            'stdout'=>$stdout===false?'':$stdout,
            'stderr'=>$stderr===false?'':$stderr
        );
    }

    /**
     * 测试入口子进程与 bin 启动器
     *
     * @return void
     */
    public function testConsoleAndBinSubprocesses(): void {
        $root=dirname(__DIR__);
        $list=$this->process(array(PHP_BINARY,$root.'/console','list'),$root);
        $this->assertSame(0,$list['code'],$list['stderr']);
        $this->assertStringContainsString('AdminService Console',$list['stdout']);

        $console=$this->process(array(PHP_BINARY,$root.'/console','cache','status','--target=config'),$root);
        $bin=$this->process(array(PHP_BINARY,$root.'/bin/cache','status','--target=config'),$root);
        $this->assertSame(0,$console['code'],$console['stderr']);
        $this->assertSame(0,$bin['code'],$bin['stderr']);
        $this->assertSame($console['stdout'],$bin['stdout']);

        $server=$this->process(array(PHP_BINARY,$root.'/bin/server','start','--dry-run'),$root.'/tests');
        $this->assertSame(0,$server['code'],$server['stderr']);
        $this->assertStringContainsString('Dry run',$server['stdout']);
    }

    /**
     * 测试用 server 命令
     *
     * @return AbstractCommand
     */
    private function serverCommand(): AbstractCommand {
        return new class extends AbstractCommand {
            public function name(): string { return 'server start'; }
            public function description(): string { return 'server'; }
            public function aliases(): array { return array('serve'); }
            public function execute(Input $input,Output $output): int { return self::SUCCESS; }
        };
    }

    /**
     * 测试用 cache 命令
     *
     * @return AbstractCommand
     */
    private function cacheCommand(): AbstractCommand {
        return new class extends AbstractCommand {
            public function name(): string { return 'cache build'; }
            public function description(): string { return 'cache'; }
            public function execute(Input $input,Output $output): int { return self::SUCCESS; }
        };
    }

}
