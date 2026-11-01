<?php

namespace AdminService\Router;

use AdminService\Cache\CompileStore;
use base\ConfigInterface;
use ReflectionClass;

use function array_key_exists;
use function filemtime;
use function filesize;
use function is_array;
use function is_dir;
use function is_file;
use function is_string;
use function ksort;
use function rtrim;
use function scandir;
use function sort;
use function str_ends_with;

/**
 * 应用级路由表提供器
 *
 * - 路由文件 / `#[Route]` 属性声明只装配一次, 后续**同一 Application/进程内**复用同一个 `Router`;
 *   默认 HTTP 入口每请求新建 `Main`, 因此不会跨请求复用
 * - 以**配置对象身份**作为失效条件: 运行期 `Config::setRepository()` 换了一整份配置时重建;
 *   同一份配置对象内的值变更不触发重建(路由配置按引导期处理)
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
     * 获取路由表(首次或配置对象替换时装配)
     *
     * @access public
     * @param ConfigInterface $config 当前配置(由调用方传入, 避免提供器自己摸门面)
     * @return Router
     * @throws \AdminService\Exception
     */
    public function get(ConfigInterface $config): Router {
        if($this->router!==null&&$this->config===$config)
            return $this->router;
        $router=$this->loadOrBuild($config);
        $this->router=$router;
        $this->config=$config;
        return $router;
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
        $store=new CompileStore($config);
        $context=$this->context($store,$config);
        $built=null;
        $payload=$store->load('routes',function() use($store,$config): string {
            return $this->fingerprint($store,$config);
        },function() use($config,&$built): ?array {
            $built=$this->build($config);
            try {
                foreach($built->getRoutes() as $route)
                    $route->compileControllerMiddlewares((array)$config->get('middlewares.controller',array()));
                return array('sorted'=>true,'routes'=>$built->export());
            } catch(\Throwable) {
                // 路由含闭包/对象或绑定无法预解析时保持实时路由, 不写缓存
                return null;
            }
        },$context);
        if($built!==null)
            return $built;
        if(is_array($payload)&&isset($payload['routes'])&&is_array($payload['routes'])) {
            try {
                $router=new Router();
                $router->import($payload['routes'],!empty($payload['sorted']));
                return $router;
            } catch(\Throwable) {
                $store->invalidate('routes');
            }
        }
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
     * 收集路径指纹
     *
     * @access private
     * @param string $path 路由文件或目录
     * @param array<string,mixed> $stats 指纹表(引用)
     * @return void
     */
    private function collectPathStats(string $path,array &$stats): void {
        if(is_dir($path)) {
            $stats['dir:'.$path]=$this->stat($path);
            foreach($this->listPhpFiles($path) as $file)
                $this->collectFileStats($file,$stats);
            return;
        }
        if(is_file($path)) {
            $this->collectFileStats($path,$stats);
            return;
        }
        $stats['missing:'.$path]=null;
    }

    /**
     * 收集属性路由控制器指纹
     *
     * @access private
     * @param string $app_path 应用根目录
     * @param array<string,mixed> $stats 指纹表(引用)
     * @return void
     */
    private function collectControllerStats(string $app_path,array &$stats): void {
        $root=rtrim($app_path,'/\\');
        if($root===''||!is_dir($root)) {
            if($root!=='')
                $stats['missing:'.$root]=null;
            return;
        }
        $apps=@scandir($root);
        if($apps===false)
            return;
        foreach($apps as $app) {
            if($app==='.'||$app==='..')
                continue;
            $controller=$root.'/'.$app.'/controller';
            if(!is_dir($controller))
                continue;
            $stats['dir:'.$controller]=$this->stat($controller);
            foreach($this->listPhpFiles($controller) as $file)
                $this->collectFileStats($file,$stats);
        }
    }

    /**
     * 收集文件指纹
     *
     * @access private
     * @param string $file 文件
     * @param array<string,mixed> $stats 指纹表(引用)
     * @return void
     */
    private function collectFileStats(string $file,array &$stats): void {
        $stats['file:'.$file]=is_file($file)?array(
            'mtime'=>(int)@filemtime($file),
            'size'=>(int)@filesize($file)
        ):null;
    }

    /**
     * 目录指纹
     *
     * @access private
     * @param string $path 目录
     * @return array<string,int>|null
     */
    private function stat(string $path): ?array {
        if(!is_dir($path))
            return null;
        return array('mtime'=>(int)@filemtime($path));
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
