<?php

namespace AdminService\Console\Command;

use AdminService\Cache\CompileStore;
use AdminService\Config\Bootstrapper;
use AdminService\Config\Env;
use AdminService\Config\Repository;
use AdminService\ContainerMetadata;
use AdminService\ReflectionCache;
use AdminService\Router\AttributeScanner;
use AdminService\Router\RouterFactory;
use AdminService\View\AllowedRoots;
use AdminService\View\Cache\FileCache;
use AdminService\View\Compiler\PhpCompiler;
use AdminService\View\Source\FileSource;
use base\AbstractCommand;
use base\Console\InputInterface;
use base\Console\OutputInterface;
use InvalidArgumentException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Throwable;

use function array_key_exists;
use function array_unique;
use function array_values;
use function count;
use function implode;
use function in_array;
use function is_array;
use function is_dir;
use function is_file;
use function is_object;
use function is_string;
use function ltrim;
use function realpath;
use function rtrim;
use function str_contains;
use function str_replace;
use function str_starts_with;
use function strlen;
use function strtolower;
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
        return '预构建编译缓存(环境、配置、路由、容器; 视图需显式指定 view)';
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
        return array('force','f','with-env');
    }

    /**
     * 选项说明
     *
     * @access public
     * @return array<int,array{names:array<int,string>,value:string|null,description:string}>
     */
    public function optionHelp(): array {
        return array(
            array('names'=>array('target'),'value'=>'all|env|config|routes|container|view','description'=>'构建范围, 默认 all(不含 env/view)'),
            array('names'=>array('force','f'),'value'=>null,'description'=>'忽略缓存指纹, 强制重建'),
            array('names'=>array('with-env'),'value'=>null,'description'=>'all 同时构建环境快照(包含 .env 全部键值)'),
        );
    }

    /**
     * 简短用法
     *
     * @access public
     * @return string
     */
    public function usage(): string {
        return 'cache build [all|env|config|routes|container|view] [--with-env] [--force]';
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
        $force=$input->hasOption('force','f');
        $store=new CompileStore($this->config);
        if($force)
            $store->force(true);
        $targets=$target===null
            ?($input->hasOption('with-env')?CacheCommandSupport::ALL_TARGETS:CacheCommandSupport::TARGETS)
            :array($target);
        $failed=false;
        $compiled_env=null;
        foreach($targets as $name) {
            if($name==='env') {
                $result=$this->buildEnv();
                $compiled_env=$result['env'];
            } elseif($name==='config') {
                $result=$this->buildConfig($force,$compiled_env);
                // 配置重建后后续目标必须使用新的配置对象与缓存路径
                $store=new CompileStore($this->config);
                if($force)
                    $store->force(true);
            } elseif($name==='routes')
                $result=$this->buildRoutes($store,$force);
            elseif($name==='view')
                $result=$this->buildView($force);
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
     * 构建环境快照
     *
     * @access private
     * @return array{status:string,reason:string|null,failure:bool,env:Env|null}
     */
    private function buildEnv(): array {
        $result=$this->bootstrapper->buildEnvCache();
        return array(
            'status'=>$result['status'],
            'reason'=>$result['reason'],
            'failure'=>$result['status']==='failed',
            'env'=>$result['env']
        );
    }

    /**
     * 构建配置缓存
     *
     * @access private
     * @param bool $force 是否强制重建
     * @param Env|null $env 环境快照(null = 当前配置/引导器的快照)
     * @return array{status:string,reason:string|null,failure:bool}
     */
    private function buildConfig(bool $force,?Env $env=null): array {
        $env??=$this->config->envSnapshot()??$this->bootstrapper->env();
        $result=$this->bootstrapper->buildConfigCache($env,$force);
        $failure=!in_array($result['status'],array('built','ready','skipped'),true);
        if(!$failure)
            $this->config=$this->bootstrapper->loadConfig($env,$force);
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
     * 构建模板编译缓存
     *
     * @access private
     * @param bool $force 是否先清理后重建
     * @return array{status:string,reason:string|null,failure:bool}
     */
    private function buildView(bool $force): array {
        $store=new CompileStore($this->config);
        if($force)
            $store->force(true);
        if(!$store->enabled('view')) {
            return array(
                'status'=>'skipped',
                'reason'=>'配置中已禁用模板缓存',
                'failure'=>false
            );
        }
        $roots=AllowedRoots::fromConfig($this->config);
        $app_root=AllowedRoots::appPath($this->config);
        if($roots===array()) {
            return array(
                'status'=>'skipped',
                'reason'=>'没有可用的模板根目录',
                'failure'=>false
            );
        }
        $cache=new FileCache($this->config,$force);
        if($force)
            $cache->clear();
        $compiler=new PhpCompiler();
        $count=0;
        $failed=0;
        $seen=array();
        try {
            foreach($roots as $root) {
                if(!is_dir($root))
                    continue;
                $only_view=$app_root!==null&&$root===$app_root;
                $iterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
                foreach($iterator as $file) {
                    if(!$file->isFile()||strtolower($file->getExtension())!=='html')
                        continue;
                    $path=$file->getPathname();
                    if($only_view&&!$this->inViewDirectory($root,$path))
                        continue;
                    $this->buildViewFile($path,$cache,$compiler,$seen,$count,$failed);
                }
            }
            $error_template=$this->config->get('app.error_template');
            if(is_string($error_template)&&is_file($error_template))
                $this->buildViewFile($error_template,$cache,$compiler,$seen,$count,$failed);
        } catch(Throwable $e) {
            return array(
                'status'=>'failed',
                'reason'=>'模板编译失败: '.$e->getMessage(),
                'failure'=>true
            );
        }
        if($failed>0) {
            return array(
                'status'=>'failed',
                'reason'=>$failed.' 个模板写入失败',
                'failure'=>true
            );
        }
        $pruned=$cache->pruneMissingSources();
        if($pruned['failed']>0) {
            return array(
                'status'=>'failed',
                'reason'=>$pruned['failed'].' 个过期产物清理失败',
                'failure'=>true
            );
        }
        $reason=$count.' 个模板';
        if($pruned['removed']>0)
            $reason.=', 清理 '.$pruned['removed'].' 个过期产物';
        return array(
            'status'=>$count>0?'built':'skipped',
            'reason'=>$reason,
            'failure'=>false
        );
    }

    /**
     * 判断模板是否位于根目录下名为 view 的路径段内
     *
     * - 按相对根目录的路径判断, 避免项目绝对路径包含 view 时误匹配
     *
     * @access private
     * @param string $root 允许根目录
     * @param string $path 模板路径
     * @return bool
     */
    private function inViewDirectory(string $root,string $path): bool {
        $root=str_replace('\\','/',rtrim($root,'/\\')).'/';
        $path=str_replace('\\','/',$path);
        if(str_starts_with($path,$root))
            $path=substr($path,strlen($root));
        return str_contains('/'.ltrim($path,'/'),'/view/');
    }

    /**
     * 构建单个模板缓存
     *
     * - 同一模板可能落在多个允许根目录下, 按真实路径去重
     *
     * @access private
     * @param string $path 模板路径
     * @param FileCache $cache 模板缓存
     * @param PhpCompiler $compiler 编译器
     * @param array<string,bool> $seen 已处理的真实路径(引用)
     * @param int $count 成功数(引用)
     * @param int $failed 失败数(引用)
     * @return void
     * @throws Throwable 模板读取失败
     */
    private function buildViewFile(string $path,FileCache $cache,PhpCompiler $compiler,array &$seen,int &$count,int &$failed): void {
        $real=realpath($path);
        $key=$real!==false?$real:str_replace('\\','/',$path);
        if(isset($seen[$key]))
            return;
        $seen[$key]=true;
        if($cache->build(new FileSource($path),$compiler))
            $count++;
        else
            $failed++;
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
                foreach((array)($middlewares[$group]??array()) as $entry)
                    $this->warmMiddleware($classes,$entry);
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
            // 配置级与控制器 / 方法上的属性中间件
            foreach($route->compileControllerMiddlewares($this->config->get('middlewares.controller',array())) as $middleware)
                $this->warmMiddleware($classes,$middleware);
            foreach($route->getMiddlewares() as $middleware)
                $this->warmMiddleware($classes,$middleware);
        }
        return array_values(array_unique($classes));
    }

    /**
     * 登记中间件类与 `handle` 方法
     *
     * @access private
     * @param array<int,class-string> $classes 类名(引用)
     * @param mixed $entry 字符串 / 实例 / 含 middleware 的条目
     * @return void
     */
    private function warmMiddleware(array &$classes,mixed $entry): void {
        if(is_string($entry)) {
            if($entry==='')
                return;
            $this->addClasses($classes,array($entry));
            $this->addWarmMethod($entry,'handle');
            return;
        }
        if(is_object($entry)) {
            $this->addClasses($classes,array($entry::class));
            $this->addWarmMethod($entry::class,'handle');
            return;
        }
        if(is_array($entry)) {
            if(array_key_exists('middleware',$entry)) {
                $this->warmMiddleware($classes,$entry['middleware']);
                return;
            }
            // `middleware` 也允许写成列表, 逐项展开
            foreach($entry as $item)
                $this->warmMiddleware($classes,$item);
        }
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
