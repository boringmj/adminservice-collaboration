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
use function ltrim;
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
     * 需要预热的动作方法(类 => 方法集合)
     * @var array<string,array<string,bool>>
     */
    private array $container_methods=array();

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
        return match($status) {
            'built' => '已构建(BUILT)',
            'ready' => '已就绪(READY)',
            'skipped' => '跳过(SKIP)',
            'failed' => '失败(FAILED)',
            'missing' => '缺失(MISSING)',
            'stale' => '过期(STALE)',
            'corrupted' => '损坏(CORRUPTED)',
            'disabled' => '禁用(DISABLED)',
            'valid' => '有效(VALID)',
            default => strtoupper($status)
        };
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
                'reason'=>'配置中已禁用路由缓存',
                'failure'=>false
            );
        }
        $factory=new RouterFactory($store);
        $before=$force?null:$factory->inspect($this->config);
        if($force&&!$store->invalidate('routes'))
            return array(
                'status'=>'failed',
                'reason'=>'无法删除路由缓存',
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
                    'reason'=>'无法写入路由缓存',
                    'failure'=>true
                );
            return array(
                'status'=>'skipped',
                'reason'=>'路由表包含不可导出的处理器',
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
                'reason'=>'配置中已禁用容器缓存',
                'failure'=>false
            );
        }
        $metadata=new ContainerMetadata(new ReflectionCache(),$this->config,$store);
        $before=$force?null:$metadata->inspect();
        if($force&&!$store->invalidate('container'))
            return array(
                'status'=>'failed',
                'reason'=>'无法删除容器缓存',
                'failure'=>true
            );
        $this->container_methods=array();
        $classes=$this->containerClasses();
        $warm=$metadata->warm($classes,$this->container_methods);
        $inspect=$metadata->inspect();
        if($store->writeFailed())
            return array(
                'status'=>'failed',
                'reason'=>'无法写入容器缓存',
                'failure'=>true
            );
        if($warm['failed']!==array())
            return array(
                'status'=>'failed',
                'reason'=>'容器预热失败: '.implode(', ',$warm['failed']),
                'failure'=>true
            );
        $warm_note=$warm['skipped']===array()?null:'跳过: '.implode(', ',$warm['skipped']);
        if($inspect['status']==='valid')
            return array(
                'status'=>($force||($before['status']??null)!=='valid')?'built':'ready',
                'reason'=>$warm_note,
                'failure'=>false
            );
        if($inspect['status']==='missing') {
            return array(
                'status'=>'skipped',
                'reason'=>$warm_note??'未生成容器元数据',
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
            foreach(array('request','controller') as $group) {
                $entries=(array)($middlewares[$group]??array());
                $this->addClasses($classes,$entries);
                foreach($entries as $entry)
                    if(is_string($entry))
                        $this->addWarmMethod($entry,'handle');
            }
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
                $method=$handler[1]??null;
                if(is_string($target)) {
                    $this->addClasses($classes,array($target));
                    if(is_string($method))
                        $this->addWarmMethod($target,$method);
                } elseif(is_object($target)) {
                    $this->addClasses($classes,array($target::class));
                    if(is_string($method))
                        $this->addWarmMethod($target::class,$method);
                }
            } elseif(is_object($handler)) {
                $this->addClasses($classes,array($handler::class));
                $this->addWarmMethod($handler::class,'__invoke');
            }
            foreach($route->getMiddlewares() as $middleware) {
                if(is_string($middleware)) {
                    $this->addClasses($classes,array($middleware));
                    $this->addWarmMethod($middleware,'handle');
                } elseif(is_object($middleware)) {
                    $this->addClasses($classes,array($middleware::class));
                    $this->addWarmMethod($middleware::class,'handle');
                }
            }
        }
        return array_values(array_unique($classes));
    }

    /**
     * 记录需要预热的动作方法
     *
     * @access private
     * @param string $class 类名
     * @param string $method 方法名
     * @return void
     */
    private function addWarmMethod(string $class,string $method): void {
        $class=ltrim($class,'\\');
        if($class===''||$method==='')
            return;
        $this->container_methods[$class][$method]=true;
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
