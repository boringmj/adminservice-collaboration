<?php

namespace AdminService\Console\Command;

use AdminService\Cache\CompileStore;
use AdminService\Cache\EnvCache;
use AdminService\Config\Bootstrapper;
use AdminService\Config\EnvSnapshot;
use AdminService\Config\Repository;
use AdminService\ContainerMetadata;
use AdminService\ReflectionCache;
use AdminService\Router\RouterFactory;
use AdminService\Runtime;
use AdminService\View\Compiler\PhpCompiler;
use base\AbstractCommand;
use base\Console\InputInterface;
use base\Console\OutputInterface;
use Closure;
use InvalidArgumentException;
use Throwable;

use function date;
use function dirname;
use function filemtime;
use function filesize;
use function is_array;
use function is_dir;
use function is_file;
use function is_string;
use function max;
use function number_format;
use function scandir;
use function sha1;
use function stat;
use function str_ends_with;

/**
 * 查看编译缓存状态
 *
 * @access public
 * @package AdminService\Console\Command
 * @version 1.0.0
 */
final class CacheStatusCommand extends AbstractCommand {

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
     * 构造方法
     *
     * @access public
     * @param Repository $config 配置仓储
     * @param Bootstrapper|null $bootstrapper 引导器(null = 新建)
     */
    public function __construct(Repository $config,?Bootstrapper $bootstrapper=null) {
        $this->config=$config;
        $this->bootstrapper=$bootstrapper??new Bootstrapper();
    }

    /**
     * 规范命令路径
     *
     * @access public
     * @return string
     */
    public function name(): string {
        return 'cache status';
    }

    /**
     * 命令说明
     *
     * @access public
     * @return string
     */
    public function description(): string {
        return '查看环境、配置、路由与容器编译缓存状态';
    }

    /**
     * 需要接收值的选项
     *
     * @access public
     * @return array<int,string>
     */
    public function valueOptions(): array {
        return array('target');
    }

    /**
     * 选项说明
     *
     * @access public
     * @return array<int,array{names:array<int,string>,value:string|null,description:string}>
     */
    public function optionHelp(): array {
        return array(
            array('names'=>array('target'),'value'=>'all|env|config|routes|container|view','description'=>'查看范围, 默认 all'),
        );
    }

    /**
     * 简短用法
     *
     * @access public
     * @return string
     */
    public function usage(): string {
        return 'cache status [all|env|config|routes|container|view]';
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
        try {
            $target=CacheCommandSupport::target($input);
        } catch(InvalidArgumentException $e) {
            $output->error('[ERROR] '.$e->getMessage());
            return self::INVALID;
        }
        $env=$this->config->envSnapshot()??$this->bootstrapper->env();
        $store=new CompileStore($this->config);
        $bootstrapper=$this->bootstrapper;
        $config_store=$bootstrapper->configStore($env);
        $output->info('运行时路径(Runtime path): '.Runtime::path());
        $output->info('编译根目录(Compile root): '.dirname($store->path('routes')));
        if(dirname($config_store->path('config'))!==dirname($store->path('routes')))
            $output->info('配置编译根目录(Config compile root): '.dirname($config_store->path('config')));
        $output->info('模式(Mode): '.$store->mode());
        $output->info('app.debug: '.((bool)$this->config->get('app.debug',false)?'true':'false'));
        $output->info('环境文件(.env): '.(is_file($env->path())?'存在(present)':'不存在(absent)'));
        $output->info('');
        $rows=array();
        foreach($target===null?CacheCommandSupport::STATUS_TARGETS:array($target) as $name) {
            $inspect=$this->inspect($name,$store,$bootstrapper,$env);
            $created=is_int($inspect['created_at']??null)?date('Y-m-d H:i:s',$inspect['created_at']):'-';
            $size=is_int($inspect['size']??null)?number_format($inspect['size']):'-';
            $rows[]=array(
                $name,
                $this->statusLabel(isset($inspect['status'])?$inspect['status']:'unknown'),
                $this->enabled($name,$store,$bootstrapper,$env)?'是(yes)':'否(no)',
                $size,
                $created,
                (string)($inspect['path']??'-'),
                (string)($inspect['reason']??'')
            );
        }
        $output->table(array('目标(Target)','状态(Status)','启用(Enabled)','大小(Size)','创建时间(Created)','文件(File)','备注(Note)'),$rows);
        return self::SUCCESS;
    }

    /**
     * 状态显示名
     *
     * @access private
     * @param string $status 内部状态
     * @return string
     */
    private function statusLabel(string $status): string {
        return match($status) {
            'valid' => '有效(valid)',
            'stale' => '过期(stale)',
            'missing' => '缺失(missing)',
            'corrupted' => '损坏(corrupted)',
            'disabled' => '禁用(disabled)',
            default => '未知(unknown)'
        };
    }

    /**
     * 检查单个目标
     *
     * @access private
     * @param string $target 目标
     * @param CompileStore $store 编译缓存
     * @param Bootstrapper $bootstrapper 引导器
     * @param \AdminService\Config\Env $env `.env` 快照
     * @return array<string,mixed>
     */
    private function inspect(string $target,CompileStore $store,Bootstrapper $bootstrapper,$env): array {
        if($target==='view') {
            $dir=dirname($store->path('view')).'/view';
            $enabled=$store->enabled('view');
            $files=array();
            $size=0;
            $mtime=null;
            if(is_dir($dir)) {
                $entries=@scandir($dir);
                if(is_array($entries))
                    foreach($entries as $entry) {
                        if($entry==='.'||$entry==='..'||!str_ends_with($entry,'.php'))
                            continue;
                        $file=$dir.'/'.$entry;
                        $files[]=$file;
                        $file_size=@filesize($file);
                        if($file_size!==false)
                            $size+=(int)$file_size;
                        $file_mtime=@filemtime($file);
                        if($file_mtime!==false)
                            $mtime=$mtime===null?$file_mtime:max($mtime,$file_mtime);
                    }
            }
            $valid=0;
            $stale=0;
            $corrupted=0;
            foreach($files as $file) {
                try {
                    $payload=@include $file;
                } catch(Throwable) {
                    $payload=null;
                }
                if(!is_array($payload)
                    ||!isset($payload['version'],$payload['fingerprint'],$payload['source'],$payload['compiled'])
                    ||!is_string($payload['version'])
                    ||!is_string($payload['fingerprint'])
                    ||!is_string($payload['source'])
                    ||!$payload['compiled'] instanceof Closure
                    ||$payload['version']!==PhpCompiler::VERSION) {
                    $corrupted++;
                    continue;
                }
                $source_stat=@stat($payload['source']);
                if($source_stat===false
                    ||sha1($source_stat['mtime'].':'.$source_stat['size'])!==$payload['fingerprint']) {
                    $stale++;
                    continue;
                }
                $valid++;
            }
            if(!$enabled)
                $status='disabled';
            elseif($files===array())
                $status='missing';
            elseif($corrupted>0)
                $status='corrupted';
            elseif($stale>0)
                $status='stale';
            else
                $status='valid';
            $reason=match($status) {
                'disabled' => '配置中已禁用模板缓存',
                'missing' => '尚未生成',
                'corrupted' => $corrupted.' 个缓存结构无效',
                'stale' => $stale.' 个模板已变化',
                default => $valid.' 个模板'
            };
            return array(
                'path'=>$dir,
                'status'=>$status,
                'size'=>$size,
                'created_at'=>$mtime,
                'reason'=>$reason
            );
        }
        if($target==='env') {
            if(EnvSnapshot::disabledBySwitch())
                return array(
                    'path'=>EnvCache::path(),
                    'status'=>'disabled',
                    'reason'=>'ADMINSERVICE_ENV_SNAPSHOT=0'
                );
            if(EnvSnapshot::usesCustomFile()&&!EnvCache::hasPathOverride())
                return array(
                    'path'=>EnvCache::path(),
                    'status'=>'disabled',
                    'reason'=>'使用 ADMINSERVICE_TEST_ENV_FILE，环境快照未参与'
                );
            return EnvCache::status(EnvSnapshot::file());
        }
        if($target==='config') {
            $status=$bootstrapper->configCacheStatus($env);
            $inspect=$status['inspect'];
            $inspect['status']=$status['status'];
            $inspect['reason']=$status['reason'];
            return $inspect;
        }
        if($target==='routes')
            return (new RouterFactory($store))->inspect($this->config);
        return (new ContainerMetadata(new ReflectionCache(),$this->config,$store))->inspect();
    }

    /**
     * 判断目标是否启用
     *
     * @access private
     * @param string $target 目标
     * @param CompileStore $store 编译缓存
     * @param Bootstrapper $bootstrapper 引导器
     * @param \AdminService\Config\Env $env `.env` 快照
     * @return bool
     */
    private function enabled(string $target,CompileStore $store,Bootstrapper $bootstrapper,$env): bool {
        if($target==='view')
            return $store->enabled('view');
        if($target==='env')
            return EnvCache::exists()
                &&!EnvSnapshot::disabledBySwitch()
                &&!(EnvSnapshot::usesCustomFile()&&!EnvCache::hasPathOverride());
        if($target==='config')
            return $bootstrapper->configStore($env)->enabled('config');
        return $store->enabled($target);
    }

}
