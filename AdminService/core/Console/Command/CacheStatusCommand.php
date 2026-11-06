<?php

namespace AdminService\Console\Command;

use AdminService\Cache\CompileStore;
use AdminService\Cache\EnvCache;
use AdminService\Config\Bootstrapper;
use AdminService\Config\EnvSnapshot;
use AdminService\Config\Repository;
use AdminService\Console\Input;
use AdminService\Console\Output;
use AdminService\ContainerMetadata;
use AdminService\ReflectionCache;
use AdminService\Router\RouterFactory;
use AdminService\Runtime;
use base\AbstractCommand;
use InvalidArgumentException;

use function date;
use function dirname;
use function is_file;
use function number_format;

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
            array('names'=>array('target'),'value'=>'all|env|config|routes|container','description'=>'查看范围, 默认 all'),
        );
    }

    /**
     * 简短用法
     *
     * @access public
     * @return string
     */
    public function usage(): string {
        return 'cache status [all|env|config|routes|container]';
    }

    /**
     * 执行命令
     *
     * @access public
     * @param Input $input 已解析参数
     * @param Output $output 输出
     * @return int
     */
    public function execute(Input $input,Output $output): int {
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
        foreach($target===null?CacheCommandSupport::ALL_TARGETS:array($target) as $name) {
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
        if($target==='env') {
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
        if($target==='env')
            return EnvCache::exists()&&!(EnvSnapshot::usesCustomFile()&&!EnvCache::hasPathOverride());
        if($target==='config')
            return $bootstrapper->configStore($env)->enabled('config');
        return $store->enabled($target);
    }

}
