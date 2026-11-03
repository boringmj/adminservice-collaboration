<?php

namespace AdminService;

use AdminService\Config\Bootstrapper;
use AdminService\Console\Command\CacheBuildCommand;
use AdminService\Console\Command\CacheClearCommand;
use AdminService\Console\Command\CacheStatusCommand;
use AdminService\Console\Command\HelpCommand;
use AdminService\Console\Command\ListCommand;
use AdminService\Console\Command\ServerStartCommand;
use AdminService\Console\CommandRegistry;
use AdminService\Console\Input;
use AdminService\Console\Output;
use base\CommandInterface;
use InvalidArgumentException;
use Throwable;

use function array_filter;
use function array_merge;
use function array_slice;
use function array_unshift;
use function array_values;
use function explode;
use function is_array;
use function is_string;
use function preg_split;
use function str_replace;
use function str_starts_with;
use function trim;
use function version_compare;

/**
 * 命令行宿主
 *
 * - 负责初始化、命令解析、分发、异常与退出码
 * - 不注册 HTTP 响应出口, 不发送 HTTP 响应, 不渲染 HTML 错误页
 * - 命令逻辑放在各命令类的 `execute()` 中
 *
 * @access public
 * @package AdminService
 * @version 1.0.0
 */
final class Console {

    /**
     * 退出码
     */
    private const EXIT_SUCCESS=0;
    private const EXIT_INVALID=2;

    /**
     * 应用
     * @var Application|null
     */
    private ?Application $application=null;

    /**
     * 引导器
     * @var Bootstrapper|null
     */
    private ?Bootstrapper $bootstrapper=null;

    /**
     * 命令注册表
     * @var CommandRegistry
     */
    private CommandRegistry $registry;

    /**
     * 输出
     * @var Output
     */
    private Output $output;

    /**
     * 是否已初始化
     * @var bool
     */
    private bool $initialized=false;

    /**
     * 是否输出异常详情
     * @var bool
     */
    private bool $verbose=false;

    /**
     * 当前是否要在引导阶段跳过配置缓存
     * @var bool
     */
    private bool $skip_boot_config_cache=false;

    /**
     * 帮助命令
     * @var HelpCommand|null
     */
    private ?HelpCommand $help=null;

    /**
     * 构造方法
     *
     * @access public
     * @param Application|null $application 应用(null = 自动引导)
     * @param Output|null $output 输出
     */
    public function __construct(?Application $application=null,?Output $output=null) {
        $this->application=$application;
        $this->output=$output??new Output();
        $this->registry=new CommandRegistry();
    }

    /**
     * 初始化
     *
     * @access public
     * @return self
     * @throws Exception
     */
    public function init(): self {
        if($this->initialized)
            return $this;
        if(version_compare(PHP_VERSION,'8.2.0','<'))
            throw new \RuntimeException('无法兼容您的PHP版本('.PHP_VERSION.'),需要PHP8.2.0及以上版本');
        $this->bootstrapper??=new Bootstrapper();
        if($this->application===null)
            $this->application=$this->bootstrapper->boot(null,!$this->skip_boot_config_cache);
        $container=$this->application->container();
        $container->instance(self::class,$this);
        $container->instance(Bootstrapper::class,$this->bootstrapper);
        $config=$this->application->config();
        $this->help=new HelpCommand($this);
        $this->register(new ListCommand($this));
        $this->register($this->help);
        $this->register(new ServerStartCommand($config));
        $this->register(new CacheStatusCommand($config,$this->bootstrapper));
        $this->register(new CacheClearCommand($config));
        $this->register(new CacheBuildCommand($this->bootstrapper,$config));
        $commands=$config->get('console.commands',array());
        if(!is_array($commands))
            throw new Exception('console.commands must be an array.',100701);
        foreach($commands as $class) {
            if(!is_string($class)||$class==='')
                throw new Exception('console.commands contains an invalid class name.',100702);
            $command=$container->get($class);
            if(!$command instanceof CommandInterface)
                throw new Exception('Console command must implement base\\CommandInterface: '.$class,100703,array(
                    'command'=>$class
                ));
            $this->register($command);
        }
        $shortcuts=$config->get('console.shortcuts',array());
        if(!is_array($shortcuts))
            throw new Exception('console.shortcuts must be an array.',100704);
        foreach($shortcuts as $shortcut=>$target) {
            if(!is_string($shortcut)||!is_string($target))
                throw new Exception('console.shortcuts contains an invalid entry.',100705);
            $this->shortcut($shortcut,$target);
        }
        $this->registry->finalize();
        $this->initialized=true;
        return $this;
    }

    /**
     * 运行命令行
     *
     * @access public
     * @param array<int,string>|null $argv 原始 argv(第一个元素为入口脚本)
     * @param Output|null $output 输出
     * @return int 退出码
     */
    public function run(?array $argv=null,?Output $output=null): int {
        if($output!==null)
            $this->output=$output;
        $tokens=array_slice($argv??(isset($_SERVER['argv'])?(array)$_SERVER['argv']:array()),1);
        $tokens=$this->extractGlobalOptions(array_values($tokens));
        $this->skip_boot_config_cache=$this->isStatusCommand($tokens);
        try {
            $this->init();
        } catch(Throwable $e) {
            return $this->renderException($e);
        }
        if($tokens===array()) {
            $default=(string)$this->application()->config()->get('console.default_command','list');
            $tokens=$this->splitCommand($default);
        }
        try {
            return $this->dispatch($tokens);
        } catch(InvalidArgumentException $e) {
            return $this->renderException($e,self::EXIT_INVALID);
        } catch(Throwable $e) {
            return $this->renderException($e);
        }
    }

    /**
     * 注册命令
     *
     * @access public
     * @param CommandInterface $command 命令
     * @return self
     */
    public function register(CommandInterface $command): self {
        $this->registry->register($command);
        return $this;
    }

    /**
     * 登记快捷绑定
     *
     * @access public
     * @param string $shortcut 简化路径
     * @param string $target 规范命令路径
     * @return self
     */
    public function shortcut(string $shortcut,string $target): self {
        $this->registry->shortcut($shortcut,$target);
        return $this;
    }

    /**
     * 命令注册表
     *
     * @access public
     * @return CommandRegistry
     */
    public function registry(): CommandRegistry {
        return $this->registry;
    }

    /**
     * 应用
     *
     * @access public
     * @return Application
     */
    public function application(): Application {
        if(!$this->initialized)
            $this->init();
        return $this->application;
    }

    /**
     * 输出
     *
     * @access public
     * @return Output
     */
    public function output(): Output {
        return $this->output;
    }

    /**
     * 分发命令
     *
     * @access private
     * @param array<int,string> $tokens 命令 token
     * @return int
     * @throws Throwable
     */
    private function dispatch(array $tokens): int {
        $result=$this->registry->resolve($tokens);
        if($result['type']==='command'&&$result['command']!==null) {
            $command=$result['command'];
            $input=new Input($result['remaining'],$command->valueOptions());
            $this->validateOptions($command,$input);
            return $command->execute($input,$this->output);
        }
        if($result['type']==='group') {
            $input=new Input($result['remaining'],array());
            foreach($input->options() as $name=>$value)
                throw new InvalidArgumentException('未知选项(Unknown option): --'.$name);
            if($this->help!==null)
                return $this->help->show(array($result['name']),$this->output);
            return self::EXIT_SUCCESS;
        }
        $name=$result['name']===''?'(空)':$result['name'];
        $this->output->error('[ERROR] 未知命令(Unknown command): '.$name);
        $this->output->error('运行 "php console list" 查看可用命令。');
        return self::EXIT_INVALID;
    }

    /**
     * 校验命令选项
     *
     * @access private
     * @param CommandInterface $command 命令
     * @param Input $input 参数
     * @return void
     * @throws InvalidArgumentException 未知选项
     */
    private function validateOptions(CommandInterface $command,Input $input): void {
        $allowed=array();
        $options=$command->valueOptions();
        $options=array_merge($options,$command->booleanOptions());
        foreach($options as $option) {
            if(!is_string($option))
                continue;
            $option=trim($option,'-');
            if($option!=='')
                $allowed[$option]=true;
        }
        foreach($input->options() as $name=>$value)
            if(!isset($allowed[$name]))
                throw new InvalidArgumentException('未知选项(Unknown option): --'.$name);
    }

    /**
     * 提取全局选项
     *
     * @access private
     * @param array<int,mixed> $tokens 原始 token
     * @return array<int,string>
     */
    private function extractGlobalOptions(array $tokens): array {
        $help=false;
        $filtered=array();
        $after_separator=false;
        foreach($tokens as $token) {
            if(!is_string($token))
                continue;
            if($token==='--') {
                $filtered[]=$token;
                $after_separator=true;
                continue;
            }
            if(!$after_separator&&($token==='--help'||$token==='-h')) {
                $help=true;
                continue;
            }
            if(!$after_separator&&($token==='--verbose'||$token==='-v')) {
                $this->verbose=true;
                continue;
            }
            $filtered[]=$token;
        }
        if($help&&($filtered[0]??null)!=='help')
            array_unshift($filtered,'help');
        return $filtered;
    }

    /**
     * 拆分默认命令
     *
     * @access private
     * @param string $command 默认命令
     * @return array<int,string>
     */
    private function splitCommand(string $command): array {
        $command=str_replace(':',' ',trim($command));
        $parts=preg_split('/\s+/',$command);
        if(!is_array($parts))
            return array();
        return array_values(array_filter($parts,static fn(string $part): bool => $part!==''));
    }

    /**
     * 判断命令是否为 cache status
     *
     * - 状态命令应看到引导前的配置缓存状态, 因此不能由 boot 写配置快照
     *
     * @access private
     * @param array<int,string> $tokens 命令 token
     * @return bool
     */
    private function isStatusCommand(array $tokens): bool {
        $parts=array();
        foreach($tokens as $token) {
            if(!is_string($token)||str_starts_with($token,'-'))
                break;
            foreach($this->splitCommand($token) as $part)
                $parts[]=$part;
        }
        return ($parts[0]??null)==='cache'&&($parts[1]??null)==='status';
    }

    /**
     * 输出异常
     *
     * @access private
     * @param Throwable $e 异常
     * @param int $code 退出码
     * @return int
     */
    private function renderException(Throwable $e,int $code=1): int {
        $this->output->error('[ERROR] '.$e->getMessage());
        if($this->verbose||$this->debugEnabled()) {
            $this->output->error('异常(Exception): '.$e::class);
            $this->output->error('文件(File): '.$e->getFile().':'.$e->getLine());
            $this->output->error($e->getTraceAsString());
        }
        return $code;
    }

    /**
     * 是否开启调试
     *
     * @access private
     * @return bool
     */
    private function debugEnabled(): bool {
        try {
            return $this->application!==null&&(bool)$this->application->config()->get('app.debug',false);
        } catch(Throwable) {
            return false;
        }
    }

}
