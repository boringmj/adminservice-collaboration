<?php

namespace AdminService\Router;

use AdminService\Cache\CompileStore;
use base\ConfigInterface;
use ReflectionClass;

use function array_key_exists;
use function count;
use function is_array;
use function is_dir;
use function is_object;
use function is_string;
use function ksort;
use function md5;
use function rtrim;
use function scandir;
use function serialize;
use function sort;
use function spl_object_hash;
use function str_ends_with;
use function str_starts_with;
use function substr;

/**
 * 应用级路由表提供器
 *
 * - 路由文件 / `#[Route]` 属性声明只装配一次, 后续**同一 Application/进程内**复用同一个 `Router`;
 *   默认 HTTP 入口每请求新建 `Main`, 因此不会跨请求复用
 * - 以**路由相关配置指纹**作为失效条件: 配置对象身份变化但值相同仍复用;
 *   路由配置值变化时重建(请求级配置副本不会导致每请求重建)
 * - 编译缓存按源文件指纹失效; 生产零探测模式只读取 `compile/routes.cache.php`
 * - 缓存包含处理器与中间件声明, 这些声明必须与请求无关: 优先用类名; 已实例化对象不得持有请求状态;
 *   闭包不得捕获请求对象
 *
 * @access public
 * @package AdminService\Router
 */
final class RouterFactory {

    /**
     * 已装配的路由表
     * @var Router|null
     */
    private ?Router $router=null;

    /**
     * 当前路由表对应的配置对象
     * @var ConfigInterface|null
     */
    private ?ConfigInterface $config=null;

    /**
     * 当前路由表对应的路由配置指纹
     * @var string|null
     */
    private ?string $route_key=null;

    /**
     * 可选的编译缓存存储(命令行动态检查 / 预热时注入)
     * @var CompileStore|null
     */
    private ?CompileStore $store=null;

    /**
     * 构造方法
     *
     * @access public
     * @param CompileStore|null $store 编译缓存存储(null = 按当前配置新建)
     */
    public function __construct(?CompileStore $store=null) {
        $this->store=$store;
    }

    /**
     * 获取路由表(首次或配置对象替换时装配)
     *
     * @access public
     * @param ConfigInterface $config 当前配置(由调用方传入, 避免提供器自己摸门面)
     * @return Router
     * @throws \AdminService\Exception
     */
    public function get(ConfigInterface $config): Router {
        $route_key=$this->routeKey($config);
        if($this->router!==null&&$this->route_key===$route_key)
            return $this->router;
        $router=$this->loadOrBuild($config);
        $this->router=$router;
        $this->config=$config;
        $this->route_key=$route_key;
        return $router;
    }

    /**
     * 清除缓存的路由表
     *
     * - 配置替换、测试复位或常驻 worker 需要强制重建时调用
     * - 下一次 `get()` 会按当前配置重新装配
     *
     * @access public
     * @return void
     */
    public function reset(): void {
        $this->router=null;
        $this->config=null;
        $this->route_key=null;
    }

    /**
     * 计算路由配置指纹
     *
     * - 请求级配置副本与共享配置值相同时复用路由表
     * - 路由配置或编译缓存模式变化时重建
     * - 含不可序列化对象时回退对象身份，避免静默复用错误路由表
     *
     * @access private
     * @param ConfigInterface $config 配置
     * @return string
     */
    private function routeKey(ConfigInterface $config): string {
        try {
            return md5(serialize(array(
                $config->get('route',array()),
                $config->get('cache.compile',array()),
                $config->get('app.path'),
                $config->get('middlewares.controller',array())
            )));
        } catch(\Throwable) {
            return 'object:'.spl_object_hash($config);
        }
    }

    /**
     * 实时装配路由表
     *
     * - 不读取或写入路由编译缓存, 供容器预热时收集处理器类
     *
     * @access public
     * @param ConfigInterface $config 当前配置
     * @return Router
     * @throws \AdminService\Exception
     */
    public function live(ConfigInterface $config): Router {
        return $this->build($config);
    }

    /**
     * 只读检查路由缓存状态
     *
     * @access public
     * @param ConfigInterface $config 当前配置
     * @return array<string,mixed>
     * @throws \AdminService\Exception
     */
    public function inspect(ConfigInterface $config): array {
        $store=$this->storeFor($config);
        $enabled=$store->enabled('routes');
        $inspect=$store->inspect('routes',$enabled);
        $status='valid';
        $reason=null;
        if(!$enabled) {
            $status='disabled';
            $reason='配置中已禁用路由缓存';
        } elseif(!$inspect['exists']) {
            $status='missing';
            $reason=$inspect['reason'];
        } elseif(!$inspect['valid']) {
            $status='corrupted';
            $reason=$inspect['reason'];
        } else {
            $payload=$inspect['payload'];
            if(!is_array($payload)
                ||(int)($payload['version']??0)!==1
                ||!isset($payload['routes'])
                ||!is_array($payload['routes'])) {
                $status='corrupted';
                $reason='路由缓存内容无效';
            } elseif($inspect['context']!==$this->context($store,$config)) {
                $status='stale';
                $reason='路由缓存上下文已变化';
            } elseif($store->shouldValidate()
                &&$inspect['fingerprint']!==$this->fingerprint($store,$config)) {
                $status='stale';
                $reason='路由源文件已变化';
            } elseif($store->shouldValidate()
                &&!$this->sourcesMatch($payload['sources']??null)) {
                $status='stale';
                $reason='路由处理器源文件已变化';
            }
        }
        $inspect['status']=$status;
        $inspect['reason']=$reason;
        return $inspect;
    }

    /**
     * 取适用于当前配置的缓存存储
     *
     * - 注入 store 在配置对象切换后不能继续沿用旧 cache.path / 开关
     * - 新建 store 时保留注入实例的 force 标记
     *
     * @access private
     * @param ConfigInterface $config 当前配置
     * @return CompileStore
     */
    private function storeFor(ConfigInterface $config): CompileStore {
        if($this->store===null)
            return new CompileStore($config);
        if($this->store->config()===$config)
            return $this->store;
        $store=new CompileStore($config);
        if($this->store->forceEnabled())
            $store->force(true);
        $this->store=$store;
        return $store;
    }

    /**
     * 从编译缓存恢复或实时装配
     *
     * @access private
     * @param ConfigInterface $config 当前配置
     * @return Router
     * @throws \AdminService\Exception
     */
    private function loadOrBuild(ConfigInterface $config): Router {
        $store=$this->storeFor($config);
        $context=$this->context($store,$config);
        $built=null;
        $builder=function() use($config,&$built): ?array {
            $built=$this->build($config);
            try {
                foreach($built->getRoutes() as $route)
                    $route->compileControllerMiddlewares($config->get('middlewares.controller',array()));
                return array(
                    'version'=>1,
                    'sorted'=>true,
                    'routes'=>$built->export(),
                    'sources'=>$this->handlerSources($built->getRoutes())
                );
            } catch(\Throwable) {
                // 路由含闭包/对象或绑定无法预解析时保持实时路由, 不写缓存
                return null;
            }
        };
        $payload=$store->load('routes',function() use($store,$config): string {
            return $this->fingerprint($store,$config);
        },$builder,$context);
        if($built!==null)
            return $built;
        $validate_sources=$store->shouldValidate();
        if(is_array($payload)
            &&(int)($payload['version']??0)===1
            &&isset($payload['routes'])
            &&is_array($payload['routes'])
            &&(!$validate_sources||$this->sourcesMatch($payload['sources']??null))) {
            try {
                $router=new Router();
                $router->import($payload['routes'],!empty($payload['sorted']));
                return $router;
            } catch(\Throwable) {
                $store->invalidate('routes');
            }
        } elseif(is_array($payload)) {
            // 旧缓存没有控制器源文件清单, 或路由处理器的类文件已变化
            $store->invalidate('routes');
        }
        $rebuilt=$builder();
        if(is_array($rebuilt))
            $store->save('routes',$this->fingerprint($store,$config),$rebuilt,$context);
        if($built!==null)
            return $built;
        return $this->build($config);
    }

    /**
     * 实时装配路由表
     *
     * @access private
     * @param ConfigInterface $config 当前配置
     * @return Router
     * @throws \AdminService\Exception
     */
    private function build(ConfigInterface $config): Router {
        $router=new Router();
        foreach((array)$config->get('route.files',array()) as $path)
            $this->loadRoutes($router,$path);
        // 属性路由: 自动扫描 + 手工登记
        $attributes=$config->get('route.attributes',array());
        if(!empty($attributes['scan']))
            $router->registerAttributes((new AttributeScanner())->scan((string)$config->get('app.path')));
        if(!empty($attributes['classes']))
            $router->registerAttributes($attributes['classes']);
        // 装配完成后校验路由名唯一, 让重名尽早暴露
        $router->assertNamesUnique();
        return $router;
    }

    /**
     * 加载路由文件或目录
     *
     * - 目录加载其中全部 `.php`, 按文件名排序保证注册顺序稳定
     *
     * @access private
     * @param Router $router 路由表
     * @param string $path 路由文件或目录路径
     * @return void
     * @throws \AdminService\Exception 路径不存在
     */
    private function loadRoutes(Router $router,string $path): void {
        if(is_dir($path)) {
            foreach($this->listPhpFiles($path) as $file)
                $router->load($file);
            return;
        }
        $router->load($path);
    }

    /**
     * 计算配置上下文
     *
     * @access private
     * @param CompileStore $store 编译缓存存储
     * @param ConfigInterface $config 当前配置
     * @return string
     */
    private function context(CompileStore $store,ConfigInterface $config): string {
        return $store->hash(array(
            'route.files'=>$config->get('route.files',array()),
            'route.attributes'=>$config->get('route.attributes',array()),
            'app.path'=>$config->get('app.path'),
            'middlewares.controller'=>$config->get('middlewares.controller',array())
        ));
    }

    /**
     * 计算路由源指纹
     *
     * @access private
     * @param CompileStore $store 编译缓存存储
     * @param ConfigInterface $config 当前配置
     * @return string
     */
    private function fingerprint(CompileStore $store,ConfigInterface $config): string {
        $stats=array();
        foreach((array)$config->get('route.files',array()) as $path)
            $this->collectPathStats((string)$path,$stats);
        $attributes=$config->get('route.attributes',array());
        if(!empty($attributes['scan']))
            $this->collectControllerStats((string)$config->get('app.path',''),$stats);
        if(!empty($attributes['classes'])) {
            foreach((array)$attributes['classes'] as $class) {
                if(!is_string($class)||$class==='')
                    continue;
                try {
                    $reflection=new ReflectionClass($class);
                    $file=$reflection->getFileName();
                    if(is_string($file))
                        $this->collectFileStats($file,$stats);
                } catch(\Throwable) {
                    $stats['missing_class:'.$class]=null;
                }
            }
        }
        ksort($stats);
        return $store->hash(array(
            'route.files'=>$config->get('route.files',array()),
            'route.attributes'=>$attributes,
            'app.path'=>$config->get('app.path'),
            'middlewares.controller'=>$config->get('middlewares.controller',array()),
            'files'=>$stats
        ));
    }

    /**
     * 收集路由处理器的控制器类文件指纹
     *
     * - 路由文件直接写 `array(Controller::class,'action')` 时, 控制器文件可能不在
     *   `route.files` / `route.attributes` 范围内; 由缓存 payload 单独保存并在命中时校验
     *
     * @access private
     * @param array<\AdminService\Router\RouteItem> $routes 路由列表
     * @return array<string,array{mtime:int,size:int}|null>
     */
    private function handlerSources(array $routes): array {
        $stats=array();
        foreach($routes as $route) {
            $handler=$route->getHandler();
            $class=null;
            $method=null;
            if(is_array($handler)&&count($handler)===2) {
                $target=$handler[0]??null;
                if(is_string($target))
                    $class=$target;
                elseif(is_object($target))
                    $class=$target::class;
                if(is_string($handler[1]))
                    $method=$handler[1];
            } elseif(is_object($handler)) {
                $class=$handler::class;
            }
            if(!is_string($class)||$class==='')
                continue;
            try {
                $reflection=new ReflectionClass($class);
                $this->collectHandlerSource($reflection,$method,$stats);
            } catch(\Throwable) {
                $stats['missing_class:'.$class]=null;
            }
        }
        ksort($stats);
        return $stats;
    }

    /**
     * 收集处理器类及其继承层次的文件指纹
     *
     * @access private
     * @param ReflectionClass<object> $reflection 处理器类反射
     * @param string|null $method 处理器方法名
     * @param array<string,array{mtime:int,size?:int}|null> $stats 指纹表(引用)
     * @return void
     */
    private function collectHandlerSource(ReflectionClass $reflection,?string $method,array &$stats): void {
        for($current=$reflection;$current!==false;$current=$current->getParentClass()) {
            $file=$current->getFileName();
            if(is_string($file))
                $this->collectFileStats($file,$stats);
        }
        if($method===null||!$reflection->hasMethod($method))
            return;
        $file=$reflection->getMethod($method)->getFileName();
        if(is_string($file))
            $this->collectFileStats($file,$stats);
    }

    /**
     * 校验缓存里的处理器类文件清单
     *
     * @access private
     * @param mixed $sources 缓存源文件清单
     * @return bool
     */
    private function sourcesMatch(mixed $sources): bool {
        if(!is_array($sources))
            return false;
        foreach($sources as $file=>$expected) {
            if(!is_string($file)||!is_array($expected))
                return false;
            if(str_starts_with($file,'file:'))
                $file=substr($file,5);
            $stat=@stat($file);
            if($stat===false
                ||(int)$stat['mtime']!==(int)($expected['mtime']??-1)
                ||(int)$stat['size']!==(int)($expected['size']??-1))
                return false;
        }
        return true;
    }

    /**
     * 收集路径指纹
     *
     * @access private
     * @param string $path 路由文件或目录
     * @param array<string,array{mtime:int,size?:int}|null> $stats 指纹表(引用)
     * @return void
     */
    private function collectPathStats(string $path,array &$stats): void {
        if(is_dir($path)) {
            foreach($this->listPhpFiles($path) as $file)
                $this->collectFileStats($file,$stats);
            return;
        }
        $this->collectFileStats($path,$stats);
    }

    /**
     * 收集属性路由控制器指纹
     *
     * @access private
     * @param string $app_path 应用根目录
     * @param array<string,array{mtime:int,size?:int}|null> $stats 指纹表(引用)
     * @return void
     */
    private function collectControllerStats(string $app_path,array &$stats): void {
        $root=rtrim($app_path,'/\\');
        if($root==='')
            return;
        $apps=@scandir($root);
        if($apps===false) {
            $stats['missing:'.$root]=null;
            return;
        }
        foreach($apps as $app) {
            if($app==='.'||$app==='..')
                continue;
            $controller=$root.'/'.$app.'/controller';
            foreach($this->listPhpFiles($controller) as $file)
                $this->collectFileStats($file,$stats);
        }
    }

    /**
     * 收集文件指纹
     *
     * @access private
     * @param string $file 文件
     * @param array<string,array{mtime:int,size?:int}|null> $stats 指纹表(引用)
     * @return void
     */
    private function collectFileStats(string $file,array &$stats): void {
        $stat=@stat($file);
        $stats['file:'.$file]=$stat===false?null:array(
            'mtime'=>(int)$stat['mtime'],
            'size'=>(int)$stat['size']
        );
    }

    /**
     * 列出目录下的 PHP 文件(按名称排序)
     *
     * @access private
     * @param string $dir 目录
     * @return array<string>
     */
    private function listPhpFiles(string $dir): array {
        $dir=rtrim($dir,'/\\');
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

}
