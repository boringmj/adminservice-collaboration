<?php

namespace AdminService\Config;

use AdminService\Application;
use AdminService\Cache\CompileStore;
use AdminService\Cache\EnvCache;
use AdminService\Config as ConfigFacade;
use AdminService\Database\DatabaseConfig;
use AdminService\Exception;
use AdminService\Runtime;
use base\Database\Db as BaseDb;

use function array_filter;
use function array_map;
use function array_values;
use function date_default_timezone_set;
use function explode;
use function in_array;
use function is_array;
use function is_file;
use function realpath;
use function scandir;
use function sort;
use function stat;
use function str_ends_with;
use function trim;
use function AdminService\env_snapshot;

/**
 * 应用引导器
 *
 * - 抽出 HTTP 与 CLI 共用的配置、编译缓存、容器与数据库配置装配
 * - **不注册** `AdminService\Error`, 也不发送 HTTP 响应; 错误出口由调用方决定
 *
 * @access public
 * @package AdminService\Config
 * @version 1.0.0
 */
final class Bootstrapper {

    /**
     * 框架配置目录
     */
    private const DEFAULT_CONFIG_DIR=__DIR__.'/../../config';

    /**
     * 配置目录
     * @var string
     */
    private string $config_dir;

    /**
     * 已建立的应用
     * @var Application|null
     */
    private ?Application $application=null;

    /**
     * 已装配的配置
     * @var Repository|null
     */
    private ?Repository $config=null;

    /**
     * 本次引导使用的 env 快照
     * @var Env|null
     */
    private ?Env $env=null;

    /**
     * 构造方法
     *
     * @access public
     * @param string|null $config_dir 配置目录(null = 框架配置目录)
     */
    public function __construct(?string $config_dir=null) {
        $this->config_dir=$config_dir??self::DEFAULT_CONFIG_DIR;
    }

    /**
     * 执行完整引导
     *
     * @access public
     * @param Env|null $env `.env` 快照(null = 进程级快照)
     * @param bool $use_config_cache 是否允许读取/写入配置编译缓存
     * @return Application
     * @throws Exception
     */
    public function boot(?Env $env=null,bool $use_config_cache=true): Application {
        if($this->application!==null)
            return $this->application;
        $env??=env_snapshot();
        $this->env=$env;
        // 先解析 runtime 根目录: 配置文件默认路径与后续缓存都依赖它
        Runtime::setPath(Runtime::path($env));
        $config=$this->loadConfig($env,false,$use_config_cache);
        date_default_timezone_set((string)$config->get('app.timezone','Asia/Shanghai'));
        ConfigFacade::setRepository($config);
        $this->loadFunction($config);
        $this->application=(new Application(null,$config))->init();
        BaseDb::setConfig(new DatabaseConfig($config,$this->application->container()));
        $this->config=$config;
        return $this->application;
    }

    /**
     * 已建立的应用
     *
     * @access public
     * @return Application|null
     */
    public function application(): ?Application {
        return $this->application;
    }

    /**
     * 已装配的配置
     *
     * @access public
     * @return Repository|null
     */
    public function config(): ?Repository {
        return $this->config;
    }

    /**
     * 本次引导使用的 env 快照
     *
     * @access public
     * @return Env
     */
    public function env(): Env {
        return $this->env??=env_snapshot();
    }

    /**
     * 加载配置
     *
     * - 优先读取 `runtime/cache/compile/config.cache.php`
     * - 未命中或快照非法时实时加载, 并尝试写入快照
     *
     * @access public
     * @param Env $env `.env` 快照
     * @param bool $force 是否忽略 debug 跳过名单
     * @param bool $use_cache 是否允许读取/写入配置编译缓存
     * @return Repository
     * @throws Exception
     */
    public function loadConfig(Env $env,bool $force=false,bool $use_cache=true): Repository {
        $loader=new Loader($this->config_dir);
        if(!$use_cache||!$this->compileConfigEnabled($env)) {
            // 存在 .env 时固定关闭配置快照; 旧快照不参与读取, 由显式清理入口处理
            return new Repository($loader->load(),$env);
        }
        $store=$this->configStore($env);
        if($force)
            $store->force(true);
        $payload=$store->load('config',function() use($store,$env): string {
            return $this->configFingerprint($store,$env);
        },function() use($loader,$env): array {
            $raw=$loader->load();
            $repository=new Repository($raw,$env);
            Application::validateConfig($repository);
            return array(
                'version'=>1,
                'validated'=>true,
                'raw'=>$raw,
                'effective'=>$repository->all()
            );
        },$this->configContext($store,$env));
        if($this->validConfigPayload($payload))
            return Repository::fromValidated($payload['raw'],$payload['effective'],$env);
        $store->invalidate('config');
        return new Repository($loader->load(),$env);
    }

    /**
     * 构造引导期编译缓存存储
     *
     * @access public
     * @param Env $env `.env` 快照
     * @return CompileStore
     */
    public function configStore(Env $env): CompileStore {
        $enabled=$this->compileEnabled($env);
        return new CompileStore(new Repository(array(
            'app'=>array('debug'=>$this->compileDebug($env)),
            'cache'=>array(
                'path'=>Runtime::path().'/cache',
                'compile'=>array(
                    'enabled'=>$enabled,
                    'mode'=>$this->compileMode($env),
                    'config'=>$this->compileConfigEnabled($env),
                    'skip_on_debug'=>$this->compileSkipOnDebug($env)
                )
            )
        )));
    }

    /**
     * 配置缓存上下文
     *
     * @access public
     * @param CompileStore $store 编译缓存存储
     * @param Env $env `.env` 快照
     * @return string
     */
    public function configContext(CompileStore $store,Env $env): string {
        return $store->hash(array(
            'runtime'=>Runtime::path(),
            'config_dir'=>realpath($this->config_dir) ?: $this->config_dir,
            'env'=>$env->all()
        ));
    }

    /**
     * 配置源指纹
     *
     * @access public
     * @param CompileStore $store 编译缓存存储
     * @param Env $env `.env` 快照
     * @param string|null $config_dir 配置目录(null = 当前配置目录)
     * @return string
     */
    public function configFingerprint(CompileStore $store,Env $env,?string $config_dir=null): string {
        $stats=array();
        foreach($this->listPhpFiles($config_dir??$this->config_dir) as $file)
            $stats['file:'.$file]=$this->statFile($file);
        return $store->hash(array('env'=>$env->all(),'files'=>$stats));
    }

    /**
     * 构建环境快照
     *
     * - 快照包含 `.env` 的全部键值, 写入前必须确认 runtime 不在文档根内
     * - 没有 `.env`、使用测试替代文件或 `ADMINSERVICE_ENV_SNAPSHOT=0` 时跳过
     * - 成功时同步进程快照与 runtime 路径, 并返回编译标记的 `Env`
     *
     * @access public
     * @return array{status:string,path:string,reason:string|null,env:Env|null}
     */
    public function buildEnvCache(): array {
        $path=EnvCache::path();
        if(EnvSnapshot::disabledBySwitch())
            return array(
                'status'=>'skipped',
                'path'=>$path,
                'reason'=>'ADMINSERVICE_ENV_SNAPSHOT=0, 已跳过环境快照',
                'env'=>null
            );
        if(EnvSnapshot::usesCustomFile()&&!EnvCache::hasPathOverride())
            return array(
                'status'=>'skipped',
                'path'=>$path,
                'reason'=>'使用 ADMINSERVICE_TEST_ENV_FILE, 已跳过环境快照',
                'env'=>null
            );
        $file=EnvSnapshot::file();
        if(!is_file($file)) {
            if(!EnvCache::clear())
                return array(
                    'status'=>'failed',
                    'path'=>$path,
                    'reason'=>'无法删除环境快照: '.$path,
                    'env'=>null
                );
            EnvSnapshot::reset();
            Runtime::reset();
            return array(
                'status'=>'skipped',
                'path'=>$path,
                'reason'=>'没有 .env, 环境快照已清理',
                'env'=>null
            );
        }
        $env=Env::fromFile($file);
        if(!EnvCache::write($file,$env))
            return array(
                'status'=>'failed',
                'path'=>$path,
                'reason'=>'无法写入环境快照: '.$path,
                'env'=>null
            );
        $compiled=Env::fromArray($env->all(),$file);
        // 同步进程状态: 后续 config/*.php 的 env() 与缓存路径都使用新值
        EnvSnapshot::replace($compiled);
        Runtime::setPath(Runtime::path($compiled));
        return array(
            'status'=>'built',
            'path'=>$path,
            'reason'=>'.env 全部键值已写入(包含敏感值): '.$path,
            'env'=>$compiled
        );
    }

    /**
     * 构建配置缓存
     *
     * @access public
     * @param Env $env `.env` 快照
     * @param bool $force 是否先清理旧文件
     * @return array{status:string,path:string,reason:string|null}
     */
    public function buildConfigCache(Env $env,bool $force=false): array {
        $store=$this->configStore($env);
        if($force)
            $store->force(true);
        $path=$store->path('config');
        if($env->path()!==''&&!$env->compiled()) {
            if(!$store->invalidate('config'))
                return array(
                    'status'=>'failed',
                    'path'=>$path,
                    'reason'=>'无法删除配置缓存'
                );
            return array(
                'status'=>'skipped',
                'path'=>$path,
                'reason'=>'.env 存在且未生成环境快照，配置缓存已禁用'
            );
        }
        if(!$store->enabled('config'))
            return array(
                'status'=>'skipped',
                'path'=>$path,
                'reason'=>'配置中已禁用配置缓存'
            );
        $before=$force?null:$this->configCacheStatus($env)['status'];
        if($force&&!$store->invalidate('config'))
            return array(
                'status'=>'failed',
                'path'=>$path,
                'reason'=>'无法删除配置缓存'
            );
        $this->loadConfig($env,$force);
        $status=$this->configCacheStatus($env,$force);
        return array(
            'status'=>$status['status']==='valid'
                ?(($force||$before!=='valid')?'built':'ready')
                :$status['status'],
            'path'=>$path,
            'reason'=>$status['reason']
        );
    }

    /**
     * 配置缓存状态
     *
     * @access public
     * @param Env $env `.env` 快照
     * @param bool $force 是否按构建模式检查(忽略 debug 跳过名单)
     * @return array{status:string,path:string,reason:string|null,inspect:array<string,mixed>}
     */
    public function configCacheStatus(Env $env,bool $force=false): array {
        $store=$this->configStore($env);
        if($force)
            $store->force(true);
        $enabled=$store->enabled('config');
        $inspect=$store->inspect('config',$enabled);
        $status='valid';
        $reason=null;
        if(!$enabled) {
            $status='disabled';
            $reason=$env->path()!==''
                ?'.env 存在且未生成环境快照，配置缓存已禁用'
                :'配置中已禁用配置缓存';
        } elseif(!$inspect['exists']) {
            $status='missing';
            $reason=$inspect['reason'];
        } elseif(!$inspect['valid']) {
            $status='corrupted';
            $reason=$inspect['reason'];
        } elseif(!$this->validConfigPayload($inspect['payload'])) {
            $status='corrupted';
            $reason='配置缓存内容无效';
        } elseif($inspect['context']!==$this->configContext($store,$env)) {
            $status='stale';
            $reason='配置缓存上下文已变化';
        } elseif($store->shouldValidate()
            &&$inspect['fingerprint']!==$this->configFingerprint($store,$env)) {
            $status='stale';
            $reason='配置源文件已变化';
        }
        return array(
            'status'=>$status,
            'path'=>$inspect['path'],
            'reason'=>$reason,
            'inspect'=>$inspect
        );
    }

    /**
     * 判断配置快照结构
     *
     * @access private
     * @param mixed $payload 快照数据
     * @return bool
     */
    private function validConfigPayload(mixed $payload): bool {
        return is_array($payload)
            &&(int)($payload['version']??0)===1
            &&!empty($payload['validated'])
            &&isset($payload['raw'],$payload['effective'])
            &&is_array($payload['raw'])
            &&is_array($payload['effective']);
    }

    /**
     * 配置缓存是否启用
     *
     * @access private
     * @param Env $env `.env` 快照
     * @return bool
     */
    private function compileEnabled(Env $env): bool {
        return CompileStore::flagEnabled($env->get('CACHE_COMPILE',true));
    }

    /**
     * 配置缓存是否启用
     *
     * - 没有 `.env` 时默认启用
     * - 有 `.env` 时只有环境快照可用才启用, 避免隐式物化敏感值
     *
     * @access private
     * @param Env $env `.env` 快照
     * @return bool
     */
    private function compileConfigEnabled(Env $env): bool {
        return $this->compileEnabled($env)&&($env->path()===''||$env->compiled());
    }

    /**
     * 配置缓存模式
     *
     * @access private
     * @param Env $env `.env` 快照
     * @return string auto / always / off
     */
    private function compileMode(Env $env): string {
        if(!$this->compileEnabled($env))
            return 'off';
        $mode=$env->get('CACHE_COMPILE_MODE','auto');
        return in_array($mode,array('auto','always','off'),true)?$mode:'auto';
    }

    /**
     * 引导期调试开关
     *
     * @access private
     * @param Env $env `.env` 快照
     * @return bool
     */
    private function compileDebug(Env $env): bool {
        return (bool)$env->get('APP_DEBUG',false);
    }

    /**
     * debug 模式下跳过的编译缓存名
     *
     * @access private
     * @param Env $env `.env` 快照
     * @return array<int,string>
     */
    private function compileSkipOnDebug(Env $env): array {
        $value=$env->get('CACHE_COMPILE_SKIP_ON_DEBUG','config');
        if(is_array($value))
            return array_values(array_filter($value,'is_string'));
        if($value===true)
            return array('config','route','container');
        if($value===false)
            return array();
        $value=trim((string)$value);
        if($value==='')
            return array();
        return array_values(array_filter(
            array_map('trim',explode(',',$value)),
            static fn(string $name): bool => $name!==''
        ));
    }

    /**
     * 文件指纹
     *
     * @access private
     * @param string $file 文件
     * @return array{mtime:int,size:int}|null
     */
    private function statFile(string $file): ?array {
        $stat=@stat($file);
        if($stat===false)
            return null;
        return array(
            'mtime'=>(int)$stat['mtime'],
            'size'=>(int)$stat['size']
        );
    }

    /**
     * 列出目录下的 PHP 文件
     *
     * @access private
     * @param string $dir 目录
     * @return array<int,string>
     */
    private function listPhpFiles(string $dir): array {
        $entries=@scandir($dir);
        if($entries===false)
            return array();
        $files=array();
        foreach($entries as $entry)
            if(str_ends_with($entry,'.php'))
                $files[]=$dir.'/'.$entry;
        sort($files);
        return $files;
    }

    /**
     * 加载函数库
     *
     * @access private
     * @param Repository $config 配置仓储
     * @return void
     */
    private function loadFunction(Repository $config): void {
        $function_path=$config->get('function.path');
        $function_loader=$config->get('function.loader');
        if(is_array($function_loader)) {
            foreach($function_loader as $function) {
                // 直接加载(因性能原因不检查文件存在性)
                include_once $function_path.'/'.$function.'.php';
            }
        }
    }

}
