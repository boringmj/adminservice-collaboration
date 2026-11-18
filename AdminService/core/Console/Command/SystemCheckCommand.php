<?php

namespace AdminService\Console\Command;

use AdminService\Cache\CompileStore;
use AdminService\Cache\EnvCache;
use AdminService\Config\Bootstrapper;
use AdminService\Config\EnvSnapshot;
use AdminService\Config\Repository;
use AdminService\ContainerMetadata;
use AdminService\Database\DatabaseConfig;
use AdminService\ReflectionCache;
use AdminService\Router\RouterFactory;
use AdminService\Runtime;
use AdminService\View\AllowedRoots;
use AdminService\View\Cache\FileCache;
use base\AbstractCommand;
use base\Console\InputInterface;
use base\Console\OutputInterface;
use base\Database\Db as BaseDb;
use PDO;
use Throwable;

use function array_filter;
use function array_key_exists;
use function array_values;
use function count;
use function ctype_digit;
use function dirname;
use function extension_loaded;
use function function_exists;
use function hrtime;
use function implode;
use function in_array;
use function ini_get;
use function is_array;
use function is_dir;
use function is_file;
use function is_int;
use function is_numeric;
use function is_readable;
use function is_string;
use function is_writable;
use function json_encode;
use function number_format;
use function strtolower;
use function strtoupper;
use function trim;
use function version_compare;

/**
 * 系统环境检查
 *
 * - 只读: 不构建缓存、不写文件、不修改配置
 * - 配置无法加载时由 `Console` 以降级方式调用, 仍会输出可检查的项
 *
 * @access public
 * @package AdminService\Console\Command
 * @version 1.0.0
 */
final class SystemCheckCommand extends AbstractCommand {

    /**
     * 检查状态
     */
    private const STATUS_OK='ok';
    private const STATUS_WARN='warn';
    private const STATUS_FAIL='fail';
    private const STATUS_SKIP='skip';

    /**
     * 默认数据库超时(秒)
     */
    private const DEFAULT_DB_TIMEOUT=2;

    /**
     * 配置
     * @var Repository
     */
    private Repository $config;

    /**
     * 引导器
     * @var Bootstrapper
     */
    private Bootstrapper $bootstrapper;

    /**
     * 引导失败原因(null = 正常启动)
     * @var string|null
     */
    private ?string $boot_error;

    /**
     * 构造方法
     *
     * @access public
     * @param Repository $config 配置
     * @param Bootstrapper $bootstrapper 引导器
     * @param string|null $boot_error 引导失败原因
     */
    public function __construct(Repository $config,Bootstrapper $bootstrapper,?string $boot_error=null) {
        $this->config=$config;
        $this->bootstrapper=$bootstrapper;
        $this->boot_error=$boot_error;
    }

    /**
     * 规范命令路径
     *
     * @access public
     * @return string
     */
    public function name(): string {
        return 'system check';
    }

    /**
     * 命令说明
     *
     * @access public
     * @return string
     */
    public function description(): string {
        return '检查环境、配置、函数文件、编译缓存、路径与数据库可用性';
    }

    /**
     * 需要接收值的选项
     *
     * @access public
     * @return array<int,string>
     */
    public function valueOptions(): array {
        return array('timeout','format');
    }

    /**
     * 布尔选项
     *
     * @access public
     * @return array<int,string>
     */
    public function booleanOptions(): array {
        return array('no-db','json','compact');
    }

    /**
     * 选项说明
     *
     * @access public
     * @return array<int,array{names:array<int,string>,value:string|null,description:string}>
     */
    public function optionHelp(): array {
        return array(
            array('names'=>array('no-db'),'value'=>null,'description'=>'跳过数据库检查'),
            array('names'=>array('format'),'value'=>'table|plain|json','description'=>'输出形式, 默认 table'),
            array('names'=>array('json'),'value'=>null,'description'=>'等价于 --format=json'),
            array('names'=>array('compact'),'value'=>null,'description'=>'隐藏正常(OK)项'),
            array('names'=>array('timeout'),'value'=>'秒','description'=>'MySQL 连接超时, 默认 2'),
        );
    }

    /**
     * 简短用法
     *
     * @access public
     * @return string
     */
    public function usage(): string {
        return 'system check [--no-db] [--format=table|plain|json] [--compact] [--timeout=2]';
    }

    /**
     * 执行命令
     *
     * @access public
     * @param InputInterface $input 已解析参数
     * @param OutputInterface $output 输出
     * @return int
     */
    public function execute(InputInterface $input,OutputInterface $output): int {
        $timeout=$this->timeout($input);
        if($timeout===null) {
            $output->error('[ERROR] 数据库超时必须是正整数(Database timeout must be a positive integer).');
            return self::INVALID;
        }
        $format=$this->format($input);
        if($format===null) {
            $output->error('[ERROR] 输出形式无效, 只支持 table / plain / json.');
            return self::INVALID;
        }
        $checks=array();
        $this->checkPhp($checks);
        $this->checkOpcache($checks);
        $this->checkEnv($checks);
        $this->checkConfig($checks);
        $this->checkFunctions($checks);
        $this->checkCaches($checks);
        $this->checkPaths($checks);
        $this->checkDatabase($checks,$input->hasOption('no-db'),$timeout);
        $summary=$this->summary($checks);
        if($input->hasOption('compact'))
            $checks=array_values(array_filter($checks,static fn(array $check): bool => $check['status']!==self::STATUS_OK));
        if($format==='json')
            $output->write($this->json($checks,$summary)."\n");
        elseif($format==='plain')
            $this->renderPlain($output,$checks,$summary);
        else
            $this->renderTable($output,$checks,$summary);
        return $summary['fail']>0||$summary['warn']>0?self::FAILURE:self::SUCCESS;
    }

    /**
     * 解析输出形式
     *
     * - `--json` 与 `--format` 同时指定时以 `--format` 为准
     *
     * @access private
     * @param InputInterface $input 参数
     * @return string|null null = 非法
     */
    private function format(InputInterface $input): ?string {
        $format=$input->option('format',null,null);
        if($format!==null) {
            if(!is_string($format))
                return null;
            $format=strtolower(trim($format));
            return in_array($format,array('table','plain','json'),true)?$format:null;
        }
        return $input->hasOption('json')?'json':'table';
    }

    /**
     * 解析数据库超时
     *
     * @access private
     * @param InputInterface $input 参数
     * @return int|null null = 非法
     */
    private function timeout(InputInterface $input): ?int {
        $value=$input->option('timeout',null,(string)self::DEFAULT_DB_TIMEOUT);
        if(is_int($value))
            return $value>0?$value:null;
        if(!is_string($value))
            return null;
        $value=trim($value);
        if($value===''||!ctype_digit($value))
            return null;
        $timeout=(int)$value;
        return $timeout>0?$timeout:null;
    }

    /**
     * 检查 PHP 版本与扩展
     *
     * @access private
     * @param array<int,array{name:string,status:string,detail:string,suggestion:string}> $checks 检查结果(引用)
     * @return void
     */
    private function checkPhp(array &$checks): void {
        $ok=version_compare(PHP_VERSION,'8.2.0','>=');
        $checks[]=$this->check('PHP 版本',($ok?self::STATUS_OK:self::STATUS_FAIL),PHP_VERSION,$ok?'':'需要 PHP 8.2.0 及以上');
        $missing=array();
        foreach(array('pdo','curl') as $extension)
            if(!extension_loaded($extension))
                $missing[]=$extension;
        $suggestion=$missing===array()?'':'安装扩展: '.implode(', ',$missing);
        $checks[]=$this->check(
            'PHP 扩展',
            $missing===array()?self::STATUS_OK:self::STATUS_FAIL,
            $missing===array()?'pdo, curl':('缺少: '.implode(', ',$missing)),
            $suggestion
        );
        if(!extension_loaded('mbstring'))
            $checks[]=$this->check('PHP 扩展(mbstring)',self::STATUS_WARN,'未安装','命令行表格按字节宽度对齐, 可选安装');
    }

    /**
     * 检查 Opcache
     *
     * - `opcache.enable` 影响 Web, `opcache.enable_cli` 影响 CLI 工具与常驻入口
     *
     * @access private
     * @param array<int,array{name:string,status:string,detail:string,suggestion:string}> $checks 检查结果(引用)
     * @return void
     */
    private function checkOpcache(array &$checks): void {
        if(!function_exists('opcache_get_status')) {
            $checks[]=$this->check('Opcache',self::STATUS_WARN,'扩展未安装','安装并启用 opcache 扩展');
            return;
        }
        $enabled=(bool)ini_get('opcache.enable');
        $cli=(bool)ini_get('opcache.enable_cli');
        $validate=(bool)ini_get('opcache.validate_timestamps');
        $file_cache=(string)ini_get('opcache.file_cache');
        $detail='enable='.($enabled?'1':'0')
            .', enable_cli='.($cli?'1':'0')
            .', validate_timestamps='.($validate?'1':'0')
            .($file_cache!==''?', file_cache='.$file_cache:'');
        $checks[]=$this->check(
            'Opcache',
            $enabled?self::STATUS_OK:self::STATUS_WARN,
            $detail,
            $enabled?'':'设置 opcache.enable=1'
        );
        if(!$cli) {
            $checks[]=$this->check('Opcache CLI',self::STATUS_WARN,'未启用','CLI 工具与常驻入口需要 opcache.enable_cli=1');
        }
        $status=function_exists('opcache_get_status')?@opcache_get_status(false):false;
        if(!is_array($status)||empty($status['opcache_enabled'])) {
            $checks[]=$this->check('Opcache 统计',self::STATUS_SKIP,'当前 SAPI 无可用统计','');
            return;
        }
        $statistics=is_array($status['opcache_statistics']??null)?$status['opcache_statistics']:array();
        $memory=is_array($status['memory_usage']??null)?$status['memory_usage']:array();
        $parts=array();
        if(is_numeric($statistics['opcache_hit_rate']??null))
            $parts[]='命中率 '.number_format((float)$statistics['opcache_hit_rate'],1).'%';
        if(isset($memory['used_memory'],$memory['free_memory']))
            $parts[]='已用 '.$this->formatBytes((int)$memory['used_memory']).' / 空闲 '.$this->formatBytes((int)$memory['free_memory']);
        if(is_int($statistics['num_cached_scripts']??null))
            $parts[]='脚本 '.$statistics['num_cached_scripts'].' 个';
        $checks[]=$this->check('Opcache 统计',self::STATUS_OK,$parts===array()?'正常':implode(', ',$parts),'');
    }

    /**
     * 格式化字节数
     *
     * @access private
     * @param int $bytes 字节
     * @return string
     */
    private function formatBytes(int $bytes): string {
        if($bytes>=1048576)
            return number_format($bytes/1048576,1).' MiB';
        if($bytes>=1024)
            return number_format($bytes/1024,1).' KiB';
        return $bytes.' B';
    }

    /**
     * 检查环境变量与快照
     *
     * @access private
     * @param array<int,array{name:string,status:string,detail:string,suggestion:string}> $checks 检查结果(引用)
     * @return void
     */
    private function checkEnv(array &$checks): void {
        $file=EnvSnapshot::file();
        $has_env=is_file($file);
        $checks[]=$this->check(
            '.env',
            self::STATUS_OK,
            $has_env?'存在':'不存在(使用进程环境变量)',
            ''
        );
        if(EnvSnapshot::disabledBySwitch()) {
            $checks[]=$this->check('环境快照',self::STATUS_SKIP,'ADMINSERVICE_ENV_SNAPSHOT=0','');
        } elseif(EnvSnapshot::usesCustomFile()&&!EnvCache::hasPathOverride()) {
            $checks[]=$this->check('环境快照',self::STATUS_SKIP,'测试环境文件, 快照未参与','');
        } else {
            $status=EnvCache::status($file);
            $state=$status['status'];
            if(!$has_env&&$state==='missing') {
                $checks[]=$this->check('环境快照',self::STATUS_OK,'没有 .env, 无需快照','');
            } else {
                $level=match($state) {
                    'valid' => self::STATUS_OK,
                    'missing' => self::STATUS_WARN,
                    'stale' => self::STATUS_WARN,
                    default => self::STATUS_FAIL
                };
                $suggestion=$level===self::STATUS_OK?'':'php console cache build env';
                $checks[]=$this->check('环境快照',$level,(string)($status['reason']??$state),$suggestion);
            }
        }
        $debug=(bool)$this->config->get('app.debug',false);
        $checks[]=$this->check(
            'APP_DEBUG',
            $debug?self::STATUS_WARN:self::STATUS_OK,
            $debug?'true':'false',
            $debug?'生产环境设置为 APP_DEBUG=false':''
        );
    }

    /**
     * 检查配置加载与配置缓存
     *
     * @access private
     * @param array<int,array{name:string,status:string,detail:string,suggestion:string}> $checks 检查结果(引用)
     * @return void
     */
    private function checkConfig(array &$checks): void {
        try {
            $env=$this->config->envSnapshot()??$this->bootstrapper->env();
        } catch(Throwable $e) {
            $checks[]=$this->check('配置加载',self::STATUS_FAIL,$e->getMessage(),'检查 .env 格式后重试');
            $checks[]=$this->check('配置缓存',self::STATUS_SKIP,'环境不可用','');
            $mode=(new CompileStore($this->config))->mode();
            $checks[]=$this->check(
                '编译缓存模式',
                $mode==='off'?self::STATUS_WARN:self::STATUS_OK,
                $mode,
                $mode==='off'?'需要缓存时设置 CACHE_COMPILE_MODE=auto 或 always':''
            );
            return;
        }
        $raw=$this->bootstrapper->rawConfigStatus($env);
        $checks[]=$raw['valid']
            ?$this->check('配置加载',self::STATUS_OK,'原始配置可加载','')
            :$this->check('配置加载',self::STATUS_FAIL,(string)$raw['reason'],'修复 config/*.php 后重试');
        // 原始配置可加载但引导失败时, 单独记录引导错误
        if($this->boot_error!==null&&$raw['valid'])
            $checks[]=$this->check('引导状态',self::STATUS_FAIL,$this->boot_error,'查看其它失败项');
        try {
            $status=$this->bootstrapper->configCacheStatus($env);
            $state=$status['status'];
            $level=match($state) {
                'valid' => self::STATUS_OK,
                'disabled' => self::STATUS_WARN,
                'missing' => self::STATUS_WARN,
                'stale' => self::STATUS_WARN,
                default => self::STATUS_FAIL
            };
            $suggestion=$level===self::STATUS_OK?'':'先运行 php console cache build env, 再运行 php console cache build config';
            $reason=$status['reason'];
            $checks[]=$this->check('配置缓存',$level,$reason!==null&&$reason!==''?$reason:$this->stateLabel($state),$suggestion);
        } catch(Throwable $e) {
            $checks[]=$this->check('配置缓存',self::STATUS_FAIL,$e->getMessage(),'检查 .env、cache.path 与 runtime 目录权限');
        }
        $mode=(new CompileStore($this->config))->mode();
        $checks[]=$this->check(
            '编译缓存模式',
            $mode==='off'?self::STATUS_WARN:self::STATUS_OK,
            $mode,
            $mode==='off'?'需要缓存时设置 CACHE_COMPILE_MODE=auto 或 always':''
        );
    }

    /**
     * 检查函数库文件
     *
     * - 引导期直接 `include_once`, 不逐项探测; 缺失文件由此处报告
     *
     * @access private
     * @param array<int,array{name:string,status:string,detail:string,suggestion:string}> $checks 检查结果(引用)
     * @return void
     */
    private function checkFunctions(array &$checks): void {
        $loader=$this->config->get('function.loader');
        if($loader===null) {
            $checks[]=$this->check(
                '函数文件',
                $this->boot_error!==null?self::STATUS_SKIP:self::STATUS_OK,
                $this->boot_error!==null?'配置不可用':'未配置 function.loader',
                ''
            );
            return;
        }
        if(!is_array($loader)) {
            $checks[]=$this->check('函数文件',self::STATUS_FAIL,'function.loader 不是数组','配置文件名数组(不含扩展名)');
            return;
        }
        if($loader===array()) {
            $checks[]=$this->check('函数文件',self::STATUS_OK,'未配置 function.loader','');
            return;
        }
        $path=$this->config->get('function.path');
        if(!is_string($path)||$path==='') {
            $checks[]=$this->check('函数文件',self::STATUS_FAIL,'function.path 为空','配置函数库目录');
            return;
        }
        $missing=array();
        foreach($loader as $file) {
            if(!is_string($file)||$file==='') {
                $missing[]='(非法条目)';
                continue;
            }
            $target=$path.'/'.$file.'.php';
            if(!is_file($target)||!is_readable($target))
                $missing[]=$file;
        }
        if($missing===array()) {
            $checks[]=$this->check('函数文件',self::STATUS_OK,count($loader).' 个文件','');
            return;
        }
        $checks[]=$this->check(
            '函数文件',
            self::STATUS_FAIL,
            '缺失或不可读: '.implode(', ',$missing),
            '补齐文件或检查 function.loader / function.path 与读权限'
        );
    }

    /**
     * 检查各编译缓存
     *
     * @access private
     * @param array<int,array{name:string,status:string,detail:string,suggestion:string}> $checks 检查结果(引用)
     * @return void
     */
    private function checkCaches(array &$checks): void {
        $store=new CompileStore($this->config);
        try {
            $status=(new RouterFactory($store))->inspect($this->config);
            $checks[]=$this->cacheCheck('路由缓存',$status,'php console cache build routes');
        } catch(Throwable $e) {
            $checks[]=$this->check('路由缓存',self::STATUS_FAIL,$e->getMessage(),'检查 route.files 与控制器');
        }
        try {
            $status=(new ContainerMetadata(new ReflectionCache(),$this->config,$store))->inspect();
            $checks[]=$this->cacheCheck('容器缓存',$status,'php console cache build container');
        } catch(Throwable $e) {
            $checks[]=$this->check('容器缓存',self::STATUS_FAIL,$e->getMessage(),'检查 app.classes 与路由处理器');
        }
        try {
            $view=(new FileCache($this->config))->inspect();
            if($view['status']==='missing')
                $checks[]=$this->check('视图缓存',self::STATUS_OK,'尚未生成, 首次渲染时创建','');
            else
                $checks[]=$this->cacheCheck('视图缓存',$view,'php console cache build view');
        } catch(Throwable $e) {
            $checks[]=$this->check('视图缓存',self::STATUS_FAIL,$e->getMessage(),'检查 cache.path 与 runtime 目录权限');
        }
    }

    /**
     * 转换编译缓存状态
     *
     * @access private
     * @param string $name 检查名
     * @param array<string,mixed> $status inspect 结果
     * @param string $suggestion 修复命令
     * @return array{name:string,status:string,detail:string,suggestion:string}
     */
    private function cacheCheck(string $name,array $status,string $suggestion): array {
        $state=(string)($status['status']??'unknown');
        $level=match($state) {
            'valid' => self::STATUS_OK,
            'disabled' => self::STATUS_WARN,
            'missing' => self::STATUS_WARN,
            'orphaned' => self::STATUS_WARN,
            'stale' => self::STATUS_WARN,
            default => self::STATUS_FAIL
        };
        $reason=$status['reason']??null;
        return $this->check(
            $name,
            $level,
            is_string($reason)&&$reason!==''?$reason:$this->stateLabel($state),
            $level===self::STATUS_OK?'':$suggestion
        );
    }

    /**
     * 缓存状态显示名
     *
     * @access private
     * @param string $state 状态
     * @return string
     */
    private function stateLabel(string $state): string {
        return match($state) {
            'valid' => '有效(valid)',
            'disabled' => '禁用(disabled)',
            'missing' => '缺失(missing)',
            'orphaned' => '源文件缺失(orphaned)',
            'stale' => '过期(stale)',
            'corrupted' => '损坏(corrupted)',
            default => $state
        };
    }

    /**
     * 检查关键路径
     *
     * @access private
     * @param array<int,array{name:string,status:string,detail:string,suggestion:string}> $checks 检查结果(引用)
     * @return void
     */
    private function checkPaths(array &$checks): void {
        try {
            $this->writableCheck($checks,'runtime 目录',Runtime::path());
        } catch(Throwable $e) {
            $checks[]=$this->check('runtime 目录',self::STATUS_FAIL,$e->getMessage(),'检查 .env 与 RUNTIME_PATH');
        }
        $log_path=$this->config->get('log.path');
        if(is_string($log_path)&&$log_path!=='')
            $this->writableCheck($checks,'日志目录',$log_path);
        $roots=AllowedRoots::fromConfig($this->config);
        $checks[]=$this->check(
            '模板根目录',
            $roots===array()?self::STATUS_FAIL:self::STATUS_OK,
            $roots===array()?'没有可用目录':implode(', ',$roots),
            $roots===array()?'检查 app.path、app.error_template 与 view.allowed_paths':''
        );
    }

    /**
     * 检查目录可写
     *
     * @access private
     * @param array<int,array{name:string,status:string,detail:string,suggestion:string}> $checks 检查结果(引用)
     * @param string $name 检查名
     * @param string $path 目录
     * @return void
     */
    private function writableCheck(array &$checks,string $name,string $path): void {
        if(is_dir($path))
            $checks[]=$this->check($name,is_writable($path)?self::STATUS_OK:self::STATUS_FAIL,$path,is_writable($path)?'':'赋予目录写权限');
        else
            $checks[]=$this->check($name,is_writable(dirname($path))?self::STATUS_WARN:self::STATUS_FAIL,$path.' (不存在)',is_writable(dirname($path))?'首次写入时自动创建':'赋予父目录写权限');
    }

    /**
     * 检查数据库连接
     *
     * @access private
     * @param array<int,array{name:string,status:string,detail:string,suggestion:string}> $checks 检查结果(引用)
     * @param bool $skip 是否跳过
     * @param int $timeout 连接超时(秒)
     * @return void
     */
    private function checkDatabase(array &$checks,bool $skip,int $timeout): void {
        if($skip) {
            $checks[]=$this->check('数据库',self::STATUS_SKIP,'已指定 --no-db','');
            return;
        }
        $connections=$this->config->get('database.connections',array());
        if(!is_array($connections)||$connections===array()) {
            $checks[]=$this->check('数据库',self::STATUS_SKIP,'未配置数据库连接','');
            return;
        }
        // 降级模式没有经过完整引导, 需要安装数据库配置
        if($this->boot_error!==null)
            BaseDb::setConfig(new DatabaseConfig($this->config));
        foreach($connections as $name=>$connection) {
            $name=(string)$name;
            if($connection===array()) {
                $checks[]=$this->check('数据库('.$name.')',self::STATUS_FAIL,'连接配置为空','检查 database.connections.'.$name);
                continue;
            }
            if(!is_array($connection)) {
                $checks[]=$this->check('数据库('.$name.')',self::STATUS_FAIL,'连接配置不是数组','检查 database.connections.'.$name);
                continue;
            }
            $detail=$this->connectionDetail($connection);
            try {
                $options=array();
                if(is_array($connection['options']??null))
                    $options=$connection['options'];
                $type=(string)($connection['type']??'mysql');
                if($type==='mysql'&&!array_key_exists(PDO::ATTR_TIMEOUT,$options))
                    $options[PDO::ATTR_TIMEOUT]=$timeout;
                $overrides=$type==='mysql'?array('options'=>$options):array();
                $start=hrtime(true);
                BaseDb::fromConfig($name,$overrides)->raw('SELECT 1');
                $elapsed=(hrtime(true)-$start)/1000;
                $checks[]=$this->check('数据库('.$name.')',self::STATUS_OK,$detail.'，'.number_format($elapsed,0).' us','');
            } catch(Throwable $e) {
                $checks[]=$this->check('数据库('.$name.')',self::STATUS_FAIL,$detail.'：'.$e->getMessage(),'检查 database.connections.'.$name.' 与网络');
            }
        }
    }

    /**
     * 连接显示信息(不含密码)
     *
     * @access private
     * @param array<string,mixed> $connection 连接配置
     * @return string
     */
    private function connectionDetail(array $connection): string {
        $type=(string)($connection['type']??'mysql');
        $host=(string)($connection['host']??'localhost');
        $port=(string)($connection['port']??'');
        $dbname=(string)($connection['dbname']??'');
        return $type.'://'.$host.($port!==''?':'.$port:'').'/'.$dbname;
    }

    /**
     * 构造检查结果
     *
     * @access private
     * @param string $name 检查名
     * @param string $status 状态
     * @param string $detail 详情
     * @param string $suggestion 建议
     * @return array{name:string,status:string,detail:string,suggestion:string}
     */
    private function check(string $name,string $status,string $detail,string $suggestion): array {
        return array(
            'name'=>$name,
            'status'=>$status,
            'detail'=>$detail,
            'suggestion'=>$suggestion
        );
    }

    /**
     * 汇总状态
     *
     * @access private
     * @param array<int,array{name:string,status:string,detail:string,suggestion:string}> $checks 检查结果
     * @return array{ok:int,warn:int,fail:int,skip:int}
     */
    private function summary(array $checks): array {
        $summary=array('ok'=>0,'warn'=>0,'fail'=>0,'skip'=>0);
        foreach($checks as $check)
            if(isset($summary[$check['status']]))
                $summary[$check['status']]++;
        return $summary;
    }

    /**
     * 输出 JSON
     *
     * @access private
     * @param array<int,array{name:string,status:string,detail:string,suggestion:string}> $checks 检查结果
     * @param array{ok:int,warn:int,fail:int,skip:int} $summary 汇总
     * @return string
     */
    private function json(array $checks,array $summary): string {
        $encoded=json_encode(array(
            'checks'=>$checks,
            'summary'=>$summary
        ),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        return is_string($encoded)?$encoded:'{}';
    }

    /**
     * 输出表格
     *
     * @access private
     * @param OutputInterface $output 输出
     * @param array<int,array{name:string,status:string,detail:string,suggestion:string}> $checks 检查结果
     * @param array{ok:int,warn:int,fail:int,skip:int} $summary 汇总
     * @return void
     */
    private function renderTable(OutputInterface $output,array $checks,array $summary): void {
        $rows=array();
        foreach($checks as $check)
            $rows[]=array(
                $check['name'],
                $this->statusLabel($check['status']),
                $check['detail'],
                $check['suggestion']
            );
        $output->table(array('检查(Check)','状态(Status)','详情(Detail)','建议(Action)'),$rows);
        $this->renderSummary($output,$summary);
    }

    /**
     * 输出逐行形式
     *
     * - 不计算列宽, 适合窄终端
     *
     * @access private
     * @param OutputInterface $output 输出
     * @param array<int,array{name:string,status:string,detail:string,suggestion:string}> $checks 检查结果
     * @param array{ok:int,warn:int,fail:int,skip:int} $summary 汇总
     * @return void
     */
    private function renderPlain(OutputInterface $output,array $checks,array $summary): void {
        foreach($checks as $check) {
            $line='['.strtoupper($check['status']).'] '.$check['name'].': '.$check['detail'];
            if($check['suggestion']!=='')
                $line.=' -> '.$check['suggestion'];
            $output->info($line);
        }
        $this->renderSummary($output,$summary);
    }

    /**
     * 输出汇总
     *
     * @access private
     * @param OutputInterface $output 输出
     * @param array{ok:int,warn:int,fail:int,skip:int} $summary 汇总
     * @return void
     */
    private function renderSummary(OutputInterface $output,array $summary): void {
        $output->info('汇总(Summary): ok '.$summary['ok'].' / warn '.$summary['warn'].' / fail '.$summary['fail'].' / skip '.$summary['skip']);
    }

    /**
     * 状态显示名
     *
     * @access private
     * @param string $status 状态
     * @return string
     */
    private function statusLabel(string $status): string {
        return match($status) {
            self::STATUS_OK => '正常(OK)',
            self::STATUS_WARN => '警告(WARN)',
            self::STATUS_FAIL => '失败(FAIL)',
            default => '跳过(SKIP)'
        };
    }

}
