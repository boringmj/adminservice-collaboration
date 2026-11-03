<?php

namespace AdminService\Console\Command;

use AdminService\Cache\CompileStore;
use AdminService\Config\Bootstrapper;
use AdminService\Config\Repository;
use AdminService\Console\Input;
use AdminService\Console\Output;
use AdminService\ContainerMetadata;
use AdminService\ReflectionCache;
use AdminService\Router\AttributeScanner;
use AdminService\Router\RouterFactory;
use base\AbstractCommand;
use InvalidArgumentException;

use function array_unique;
use function array_values;
use function count;
use function implode;
use function in_array;
use function is_array;
use function is_object;
use function is_string;
use function strtoupper;

/**
 * 预构建编译缓存
 *
 * @access public
 * @package AdminService\Console\Command
 * @version 1.0.0
 */
final class CacheBuildCommand extends AbstractCommand {

    /**
     * 引导器
     * @var Bootstrapper
     */
    private Bootstrapper $bootstrapper;

    /**
     * 配置
     * @var Repository
     */
    private Repository $config;

    /**
     * 构造方法
     *
     * @access public
     * @param Bootstrapper $bootstrapper 引导器
     * @param Repository $config 配置仓储
     */
    public function __construct(Bootstrapper $bootstrapper,Repository $config) {
        $this->bootstrapper=$bootstrapper;
        $this->config=$config;
    }

    /**
     * 规范命令路径
     *
     * @access public
     * @return string
     */
    public function name(): string {
        return 'cache build';
    }

    /**
     * 命令说明
     *
     * @access public
     * @return string
     */
    public function description(): string {
        return '预构建配置、路由与容器编译缓存';
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
     * 布尔选项
     *
     * @access public
     * @return array<int,string>
     */
    public function booleanOptions(): array {
        return array('force','f');
    }

    /**
     * 简短用法
     *
     * @access public
     * @return string
     */
    public function usage(): string {
        return 'cache build [all|config|routes|container] [--force]';
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
        $force=$input->hasOption('force','f');
        $store=new CompileStore($this->config);
        if($force)
            $store->force(true);
        $targets=$target===null?CacheCommandSupport::TARGETS:array($target);
        $failed=false;
        foreach($targets as $name) {
            if($name==='config')
                $result=$this->buildConfig($force);
            elseif($name==='routes')
                $result=$this->buildRoutes($store,$force);
            else
                $result=$this->buildContainer($store,$force);
            $output->info('['.$this->statusLabel($result['status']).'] '.$name.($result['reason']!==null?' - '.$result['reason']:''));
            if($result['failure'])
                $failed=true;
        }
        return $failed?self::FAILURE:self::SUCCESS;
    }

    /**
     * 构建状态显示名
     *
     * @access private
     * @param string $status 内部状态
     * @return string
     */
    private function statusLabel(string $status): string {
        return $status==='skipped'?'SKIP':strtoupper($status);
    }

    /**
     * 构建配置缓存
     *
     * @access private
     * @param bool $force 是否强制重建
     * @return array{status:string,reason:string|null,failure:bool}
     */
    private function buildConfig(bool $force): array {
        $env=$this->config->envSnapshot()??$this->bootstrapper->env();
        $result=$this->bootstrapper->buildConfigCache($env,$force);
        $failure=!in_array($result['status'],array('built','ready','skipped'),true);
        return array(
            'status'=>$result['status'],
            'reason'=>$result['reason'],
            'failure'=>$failure
        );
    }

    /**
     * 构建路由缓存
     *
     * @access private
     * @param CompileStore $store 编译缓存
     * @param bool $force 是否强制重建
     * @return array{status:string,reason:string|null,failure:bool}
     */
    private function buildRoutes(CompileStore $store,bool $force): array {
        if(!$store->enabled('routes')) {
            return array(
                'status'=>'skipped',
                'reason'=>'route cache is disabled by configuration',
                'failure'=>false
            );
        }
        $factory=new RouterFactory($store);
        $before=$force?null:$factory->inspect($this->config);
        if($force&&!$store->invalidate('routes'))
            return array(
                'status'=>'failed',
                'reason'=>'route cache could not be removed',
                'failure'=>true
            );
        $factory->get($this->config);
        $inspect=$factory->inspect($this->config);
        if($inspect['status']==='valid')
            return array(
                'status'=>($force||($before['status']??null)!=='valid')?'built':'ready',
                'reason'=>null,
                'failure'=>false
            );
        if($inspect['status']==='missing') {
            if($store->writeFailed())
                return array(
                    'status'=>'failed',
                    'reason'=>'route cache could not be written',
                    'failure'=>true
                );
            return array(
                'status'=>'skipped',
                'reason'=>'route table contains non-exportable handlers',
                'failure'=>false
            );
        }
        return array(
            'status'=>$inspect['status'],
            'reason'=>$inspect['reason'],
            'failure'=>true
        );
    }

    /**
     * 构建容器元数据缓存
     *
     * @access private
     * @param CompileStore $store 编译缓存
     * @param bool $force 是否强制重建
     * @return array{status:string,reason:string|null,failure:bool}
     */
    private function buildContainer(CompileStore $store,bool $force): array {
        if(!$store->enabled('container')) {
            return array(
                'status'=>'skipped',
                'reason'=>'container cache is disabled by configuration',
                'failure'=>false
            );
        }
        $metadata=new ContainerMetadata(new ReflectionCache(),$this->config,$store);
        $before=$force?null:$metadata->inspect();
        if($force&&!$store->invalidate('container'))
            return array(
                'status'=>'failed',
                'reason'=>'container cache could not be removed',
                'failure'=>true
            );
        $classes=$this->containerClasses();
        $warm=$metadata->warm($classes);
        $inspect=$metadata->inspect();
        if($store->writeFailed())
            return array(
                'status'=>'failed',
                'reason'=>'container cache could not be written',
                'failure'=>true
            );
        if($warm['failed']!==array())
            return array(
                'status'=>'failed',
                'reason'=>'container warm failed: '.implode(', ',$warm['failed']),
                'failure'=>true
            );
        $warm_note=$warm['skipped']===array()?null:'skipped: '.implode(', ',$warm['skipped']);
        if($inspect['status']==='valid')
            return array(
                'status'=>($force||($before['status']??null)!=='valid')?'built':'ready',
                'reason'=>$warm_note,
                'failure'=>false
            );
        if($inspect['status']==='missing') {
            return array(
                'status'=>'skipped',
                'reason'=>$warm_note??'no container metadata was generated',
                'failure'=>false
            );
        }
        return array(
            'status'=>$inspect['status'],
            'reason'=>$inspect['reason'],
            'failure'=>true
        );
    }

    /**
     * 收集需要预热的类
     *
     * @access private
     * @return array<int,class-string>
     */
    private function containerClasses(): array {
        $classes=array();
        $this->addClasses($classes,(array)$this->config->get('app.classes',array()));
        $this->addClasses($classes,(array)$this->config->get('app.alias',array()));
        $middlewares=$this->config->get('middlewares',array());
        if(is_array($middlewares)) {
            $this->addClasses($classes,(array)($middlewares['request']??array()));
            $this->addClasses($classes,(array)($middlewares['controller']??array()));
        }
        $attributes=(array)$this->config->get('route.attributes',array());
        $this->addClasses($classes,(array)($attributes['classes']??array()));
        if(!empty($attributes['scan'])) {
            $app_path=(string)$this->config->get('app.path','');
            if($app_path!=='')
                $this->addClasses($classes,(new AttributeScanner())->scan($app_path));
        }
        $router=(new RouterFactory())->live($this->config);
        foreach($router->getRoutes() as $route) {
            $handler=$route->getHandler();
            if(is_array($handler)&&count($handler)>=2) {
                $target=$handler[0]??null;
                if(is_string($target))
                    $this->addClasses($classes,array($target));
                elseif(is_object($target))
                    $this->addClasses($classes,array($target::class));
            } elseif(is_object($handler)) {
                $this->addClasses($classes,array($handler::class));
            }
        }
        return array_values(array_unique($classes));
    }

    /**
     * 加入类名
     *
     * @access private
     * @param array<int,string> $classes 类名(引用)
     * @param array<int|string,string> $values 值
     * @return void
     */
    private function addClasses(array &$classes,array $values): void {
        foreach($values as $value)
            if(is_string($value)&&$value!=='')
                $classes[]=$value;
    }

}
