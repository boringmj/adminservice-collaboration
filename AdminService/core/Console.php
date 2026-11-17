<?php

namespace AdminService;

use AdminService\Config\Bootstrapper;
use AdminService\Config\Env;
use AdminService\Config\Repository;
use AdminService\Console\Command\CacheBuildCommand;
use AdminService\Console\Command\CacheClearCommand;
use AdminService\Console\Command\CacheStatusCommand;
use AdminService\Console\Command\HelpCommand;
use AdminService\Console\Command\ListCommand;
use AdminService\Console\Command\ServerStartCommand;
use AdminService\Console\Command\SystemCheckCommand;
use AdminService\Console\Command\WorkerStartCommand;
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
use function count;
use function dirname;
use function implode;
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
     * 引导前解析原始配置的失败原因
     * @var Throwable|null
     */
    private ?Throwable $preboot_config_error=null;

    /**
     * 引导前从配置快照恢复的配置
     * @var Repository|null
     */
    private ?Repository $recovery_config=null;

    /**
     * 帮助命令
     * @var HelpCommand|null
     */
    private ?HelpCommand $help=null;

    /**
     * 本次运行是否由全局 `--help` 注入帮助命令
     * @var bool
     */
    private bool $help_option=false;

    /**
     * 构造方法
     *
     * @access public
     * @param Application|null $application 应用(null = 自动引导)
     * @param Output|null $output 输出
     * @param Bootstrapper|null $bootstrapper 引导器(null = 自动新建)
     */
    public function __construct(?Application $application=null,?Output $output=null,?Bootstrapper $bootstrapper=null) {
        $this->application=$application;
        $this->output=$output??new Output();
        $this->bootstrapper=$bootstrapper;
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
        $this->register(new WorkerStartCommand($config,$this->application));
        $this->register(new CacheStatusCommand($config,$this->bootstrapper));
        $this->register(new CacheClearCommand($config));
        $this->register(new CacheBuildCommand($this->bootstrapper,$config));
        $this->register(new SystemCheckCommand($config,$this->bootstrapper));
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
        $this->help_option=false;
        $this->preboot_config_error=null;
        $this->recovery_config=null;
        $tokens=$this->extractGlobalOptions(array_values($tokens));
        try {
            $this->skip_boot_config_cache=$this->isStatusCommand($tokens);
            $this->init();
        } catch(Throwable $e) {
            // system check 用于诊断引导失败, 不依赖引导成功
            $code=$this->recoverSystemCheck($tokens,$e);
            if($code!==null)
                return $code;
            // 原始配置损坏时仍允许执行 cache clear
            if($this->preboot_config_error!==null) {
                $code=$this->recoverCacheClear($tokens,$e);
                if($code!==null)
                    return $code;
            }
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
            // 全局 --help 会保留原命令选项, 只对这种注入的 help 调用跳过选项校验
            if(!($command instanceof HelpCommand&&$this->help_option))
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
        if($help&&($filtered[0]??null)!=='help') {
            $this->help_option=true;
            array_unshift($filtered,'help');
        }
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
     * 判断命令是否只读诊断命令
     *
     * - `cache status` 与 `system check` 应看到引导前的配置缓存状态, 不能由 boot 写配置快照
     * - 快捷绑定与默认命令来自配置, 需在引导前单独读取; 其它命令会多读一次配置
     *
     * @access private
     * @param array<int,string> $tokens 命令 token
     * @return bool
     */
    private function isStatusCommand(array $tokens): bool {
        // 已注入应用时不执行自动引导, 不会写配置缓存; 版本检查交给 init
        if($this->application!==null||version_compare(PHP_VERSION,'8.2.0','<'))
            return false;
        $parts=$this->commandParts($tokens);
        if($this->startsWithReadOnly($parts))
            return true;
        $this->bootstrapper??=new Bootstrapper();
        try {
            $config=$this->bootstrapper->loadConfig($this->bootstrapper->env(),false,false);
        } catch(Throwable $e) {
            // 快捷绑定与默认命令来自配置, 解析失败时交给引导阶段
            $this->preboot_config_error=$e;
            // 引导失败会删除过期快照, 先取出缓存路径供恢复命令使用; env 也无法解析时跳过
            try {
                $this->recovery_config=$this->bootstrapper->recoveryConfig($this->bootstrapper->env());
            } catch(Throwable) {
                $this->recovery_config=null;
            }
            return false;
        }
        if($parts===array())
            $parts=$this->splitCommand((string)$config->get('console.default_command','list'));
        return $this->matchesReadOnly($parts,$this->shortcutPaths($config->get('console.shortcuts',array())));
    }

    /**
     * 命令路径是否以只读诊断命令开头
     *
     * @access private
     * @param array<int,string> $parts 命令路径段
     * @return bool
     */
    private function startsWithReadOnly(array $parts): bool {
        if(($parts[0]??null)==='cache'&&($parts[1]??null)==='status')
            return true;
        return ($parts[0]??null)==='system'&&($parts[1]??null)==='check';
    }

    /**
     * 取命令路径段
     *
     * @access private
     * @param array<int,string> $tokens 命令 token
     * @return array<int,string>
     */
    private function commandParts(array $tokens): array {
        $parts=array();
        foreach($tokens as $token) {
            if(!is_string($token)||str_starts_with($token,'-'))
                break;
            foreach($this->splitCommand($token) as $part)
                $parts[]=$part;
        }
        return $parts;
    }

    /**
     * 归一化快捷绑定
     *
     * @access private
     * @param mixed $shortcuts 配置里的快捷绑定
     * @return array<string,array<int,string>> 快捷绑定路径 => 目标路径段
     */
    private function shortcutPaths(mixed $shortcuts): array {
        if(!is_array($shortcuts))
            return array();
        $paths=array();
        foreach($shortcuts as $shortcut=>$target) {
            if(!is_string($shortcut)||!is_string($target))
                continue;
            $key=implode(' ',$this->splitCommand($shortcut));
            $value=$this->splitCommand($target);
            if($key===''||$value===array())
                continue;
            $paths[$key]=$value;
        }
        return $paths;
    }

    /**
     * 判断命令路径经快捷绑定展开后是否为只读诊断命令
     *
     * - 与命令注册表一致取最长的快捷绑定前缀; 目标为分组时后续段继续拼接
     *
     * @access private
     * @param array<int,string> $parts 命令路径段
     * @param array<string,array<int,string>> $paths 快捷绑定
     * @return bool
     */
    private function matchesReadOnly(array $parts,array $paths): bool {
        if($this->startsWithReadOnly($parts))
            return true;
        $match=null;
        $prefix=array();
        foreach($parts as $part) {
            $prefix[]=$part;
            $key=implode(' ',$prefix);
            if(isset($paths[$key]))
                $match=array($key,count($prefix));
        }
        if($match===null)
            return false;
        $expanded=array_merge($paths[$match[0]],array_slice($parts,$match[1]));
        return $this->startsWithReadOnly($expanded);
    }

    /**
     * 配置无法加载时执行缓存清理
     *
     * - 只处理直接写出的 `cache clear`; 快捷绑定依赖配置, 此时不可用
     * - 缓存路径取自上下文一致的配置快照, 否则使用 runtime 默认目录
     *
     * @access private
     * @param array<int,string> $tokens 命令 token
     * @param Throwable $e 引导失败原因
     * @return int|null null = 不是 `cache clear`, 未处理
     */
    private function recoverCacheClear(array $tokens,Throwable $e): ?int {
        if($this->application!==null)
            return null;
        $consumed=$this->cacheClearTokens($tokens);
        if($consumed===null)
            return null;
        $bootstrapper=$this->bootstrapper??=new Bootstrapper();
        $env=$this->recoveryEnv($bootstrapper);
        $command=new CacheClearCommand($this->recoveryConfig($bootstrapper,$env));
        try {
            $input=new Input(array_slice($tokens,$consumed),$command->valueOptions());
            $this->validateOptions($command,$input);
        } catch(InvalidArgumentException $invalid) {
            $this->output->error('[ERROR] '.$invalid->getMessage());
            return self::EXIT_INVALID;
        }
        $this->output->error('[WARN] 配置加载失败(Config load failed): '.$e->getMessage());
        $code=$command->execute($input,$this->output);
        if($code===self::EXIT_SUCCESS) {
            $this->output->info('缓存路径来自上下文一致的配置快照或 runtime 默认目录。');
            if($env===null)
                $this->output->info('环境无法加载, 缓存路径使用 runtime 默认目录。');
        }
        return $code;
    }

    /**
     * 配置无法加载时执行系统检查
     *
     * @access private
     * @param array<int,string> $tokens 命令 token
     * @param Throwable $e 引导失败原因
     * @return int|null null = 不是 `system check`, 未处理
     */
    private function recoverSystemCheck(array $tokens,Throwable $e): ?int {
        if($this->application!==null)
            return null;
        $consumed=$this->systemCheckTokens($tokens);
        if($consumed===null)
            return null;
        $bootstrapper=$this->bootstrapper??=new Bootstrapper();
        $env=$this->recoveryEnv($bootstrapper);
        $command=new SystemCheckCommand($this->recoveryConfig($bootstrapper,$env),$bootstrapper,$e->getMessage());
        try {
            $input=new Input(array_slice($tokens,$consumed),$command->valueOptions());
            $this->validateOptions($command,$input);
        } catch(InvalidArgumentException $invalid) {
            $this->output->error('[ERROR] '.$invalid->getMessage());
            return self::EXIT_INVALID;
        }
        return $command->execute($input,$this->output);
    }

    /**
     * 恢复路径的环境与 runtime
     *
     * - `.env` 无法解析时使用框架默认 runtime, 不抛出异常
     *
     * @access private
     * @param Bootstrapper $bootstrapper 引导器
     * @return Env|null null = 环境无法加载
     */
    private function recoveryEnv(Bootstrapper $bootstrapper): ?Env {
        try {
            $env=$bootstrapper->env();
            Runtime::setPath(Runtime::path($env));
            return $env;
        } catch(Throwable) {
            // 已解析过的 runtime 保留; 否则使用安装目录默认值
            try {
                Runtime::path();
            } catch(Throwable) {
                Runtime::setPath(dirname(__DIR__).'/runtime');
            }
            return null;
        }
    }

    /**
     * 恢复路径的配置
     *
     * @access private
     * @param Bootstrapper $bootstrapper 引导器
     * @param Env|null $env 环境快照
     * @return Repository
     */
    private function recoveryConfig(Bootstrapper $bootstrapper,?Env $env): Repository {
        $config=$this->recovery_config;
        if($config===null&&$env!==null) {
            try {
                $config=$bootstrapper->recoveryConfig($env);
            } catch(Throwable) {
                $config=null;
            }
        }
        return $config??new Repository(array(
            'cache'=>array('path'=>Runtime::path().'/cache')
        ));
    }

    /**
     * 取 `system check` 命令占用的 token 数
     *
     * - 只接受 `system:check` 与 `system check` 两种写法
     *
     * @access private
     * @param array<int,string> $tokens 命令 token
     * @return int|null null = 不是 `system check`
     */
    private function systemCheckTokens(array $tokens): ?int {
        $first=$this->splitCommand($tokens[0]??'');
        if($first===array('system','check'))
            return 1;
        if($first===array('system')&&$this->splitCommand($tokens[1]??'')===array('check'))
            return 2;
        return null;
    }

    /**
     * 取 `cache clear` 命令占用的 token 数
     *
     * - 只接受 `cache:clear` 与 `cache clear` 两种写法
     * - 多余路径段属于未知命令, 不能按 `cache clear` 处理
     *
     * @access private
     * @param array<int,string> $tokens 命令 token
     * @return int|null null = 不是 `cache clear`
     */
    private function cacheClearTokens(array $tokens): ?int {
        $first=$this->splitCommand($tokens[0]??'');
        if($first===array('cache','clear'))
            return 1;
        if($first===array('cache')&&$this->splitCommand($tokens[1]??'')===array('clear'))
            return 2;
        return null;
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
        if($this->verbose) {
            $this->output->error('异常(Exception): '.$e::class);
            $this->output->error('文件(File): '.$e->getFile().':'.$e->getLine());
            $this->output->error($e->getTraceAsString());
        }
        return $code;
    }

}
