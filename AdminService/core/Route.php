<?php

namespace AdminService;

use base\Response;
use base\Route as BaseRoute;
use base\Attribute\Middleware;
use AdminService\Router\AttributeScanner;
use AdminService\Router\RouteItem;
use AdminService\Router\Router;
use ReflectionClass;
use ReflectionException;

use function array_merge;
use function class_exists;
use function count;
use function glob;
use function is_array;
use function is_dir;
use function is_numeric;
use function is_string;
use function lcfirst;
use function preg_match;
use function rtrim;
use function sort;
use function urldecode;
use function usort;

/**
 * 路由协调器
 *
 * - 显式路由由路由文件与控制器 `#[Route]` 属性声明, 未命中即 404
 * - 路由表在首次请求时装配, 之后复用同一实例
 */
final class Route extends BaseRoute {

    /**
     * 显式路由表
     * @var Router|null
     */
    private ?Router $router=null;

    /**
     * 运行请求
     *
     * @access public
     * @return void
     * @throws Exception|ReflectionException
     */
    public function run(): void {
        $uri=$this->request->uri();
        $method=$this->request->method();
        $match=$this->router()->find($method,$uri);
        if($match!==null) {
            $this->runRoute($match[0],$match[1]);
            return;
        }
        // 路径存在但方法不符: 告知允许的方法; OPTIONS 以 204 应答
        $allowed=$this->router()->allowedMethods($uri);
        if($allowed!==array()) {
            $this->notAllowed($allowed,$method==='OPTIONS');
            return;
        }
        $this->notFound();
    }

    /**
     * 方法不被允许时的响应
     *
     * - 405 与 204 均带 `Allow` 头, 客户端据此得知可用方法
     *
     * @access private
     * @param array<string> $allowed 允许的请求方法
     * @param bool $isOptions 是否为 OPTIONS 请求
     * @return void
     * @throws Exception|ReflectionException
     */
    private function notAllowed(array $allowed,bool $isOptions): void {
        $response=App::get(Response::class);
        $response->header('Allow',implode(', ',$allowed));
        if($isOptions) {
            $response->status(204);
            $response->body('');
            return;
        }
        $response->status(405);
        $response->body('405 Method Not Allowed');
    }

    /**
     * 未命中路由时的响应
     *
     * - 直接返回 404: 不回显请求路径, 也不泄漏目录结构
     *
     * @access private
     * @return void
     * @throws Exception|ReflectionException
     */
    private function notFound(): void {
        $response=App::get(Response::class);
        $response->status(404);
        $response->body('404 Not Found');
    }

    /**
     * 获取路由表(首次访问时装配)
     *
     * - 路由文件与属性声明只解析一次, 后续请求复用同一实例
     *
     * @access private
     * @return Router
     * @throws Exception
     */
    private function router(): Router {
        if($this->router!==null)
            return $this->router;
        $this->router=new Router();
        foreach((array)Config::get('route.files',array()) as $path)
            $this->loadRoutes($path);
        // 属性路由: 自动扫描 + 手工登记
        $attributes=Config::get('route.attributes',array());
        if(!empty($attributes['scan']))
            $this->router->registerAttributes((new AttributeScanner())->scan((string)Config::get('app.path')));
        if(!empty($attributes['classes']))
            $this->router->registerAttributes($attributes['classes']);
        // 装配完成后校验路由名唯一, 让重名尽早暴露
        $this->router->assertNamesUnique();
        // 登记到容器, 供运行时(控制器/视图)反向生成 URL: App::get(Router::class)->url(...)
        App::set(Router::class,$this->router);
        return $this->router;
    }

    /**
     * 加载路由文件或目录
     *
     * - 目录加载其中全部 `.php`, 按文件名排序保证注册顺序稳定
     *
     * @access private
     * @param string $path 路由文件或目录路径
     * @return void
     * @throws Exception 路径不存在
     */
    private function loadRoutes(string $path): void {
        if(is_dir($path)) {
            $files=glob(rtrim($path,'/\\').'/*.php');
            sort($files);
            foreach($files as $file)
                $this->router->load($file);
            return;
        }
        $this->router->load($path);
    }

    /**
     * 执行命中的路由
     *
     * - 路径参数写入请求的 attributes 区(路由上下文), 不并入 GET
     * - 中间件按 group → route → controller 顺序包裹(请求级已在匹配前执行)
     *
     * @access private
     * @param RouteItem $route 命中的路由
     * @param array<string,string> $params 路径参数
     * @return void
     * @throws Exception|ReflectionException
     */
    private function runRoute(RouteItem $route,array $params): void {
        $handler=$route->getHandler();
        // 路径参数写入属性区(不并入GET): 参数检索时属性优先, 同名查询参数仍按查询参数读取
        foreach(self::decodeParams($params) as $name=>$value)
            $this->request->setAttribute($name,$value);
        // 写入路由上下文, 供 App::getAppName 等继续可用
        App::setData('route_info',self::handlerRouteInfo($handler));
        $middlewares=array_merge(
            $route->getGroupMiddlewares(),
            $route->getRouteMiddlewares(),
            $this->controllerMiddlewares($handler)
        );
        $response=App::get(Response::class);
        (new Pipeline($middlewares,$this->request))->then(function() use ($handler,$response): void {
            $response->body($this->callHandler($handler));
        });
    }

    /**
     * 组装控制器级中间件
     *
     * - 来源: 配置(`middlewares.controller`) → 类上 `#[Middleware]` → 方法上 `#[Middleware]`
     * - 三者**同级**: 按 `priority` 排序(数值大者靠外), 同优先级时按上述收集顺序
     *
     * @access private
     * @param mixed $handler 处理器
     * @return array<string|object>
     */
    private function controllerMiddlewares(mixed $handler): array {
        $entries=Pipeline::normalize(Config::get('middlewares.controller',array()));
        if(is_array($handler)&&count($handler)===2&&is_string($handler[0])&&class_exists($handler[0])) {
            $reflection=new ReflectionClass($handler[0]);
            $entries=array_merge($entries,self::middlewareEntries($reflection->getAttributes(Middleware::class)));
            if($reflection->hasMethod($handler[1]))
                $entries=array_merge($entries,self::middlewareEntries(
                    $reflection->getMethod($handler[1])->getAttributes(Middleware::class)
                ));
        }
        return Pipeline::order($entries);
    }

    /**
     * 把中间件属性展开为中间件条目
     *
     * @access private
     * @param array<\ReflectionAttribute> $attributes 属性集合
     * @return array<array{middleware:string|object,priority:int}>
     */
    private static function middlewareEntries(array $attributes): array {
        $entries=array();
        foreach($attributes as $attribute) {
            /** @var Middleware $declaration */
            $declaration=$attribute->newInstance();
            foreach($declaration->getMiddlewares() as $middleware)
                $entries[]=array('middleware'=>$middleware,'priority'=>$declaration->getPriority());
        }
        return $entries;
    }

    /**
     * 调用处理器
     *
     * - 控制器是请求级对象: 每次分发都强制新建, 避免复用容器中上一次请求的实例(其请求/响应对象已过期)
     *
     * @access private
     * @param mixed $handler 处理器
     * @return mixed
     * @throws Exception|ReflectionException
     */
    private function callHandler(mixed $handler): mixed {
        if(is_array($handler)&&count($handler)===2) {
            // 与既有约定一致: 控制器类名与实例均登记到容器
            if(is_string($handler[0])) {
                App::setClass('Controller',$handler[0]);
                $handler[0]=App::make($handler[0],true);
            }
            App::set('Controller',$handler[0]);
            return App::exec_class_function($handler[0],$handler[1],$this->controllerArgs());
        }
        return App::exec_function($handler,$this->controllerArgs());
    }

    /**
     * 提取控制器方法参数
     *
     * - 只注入**路由参数**(属性区): 查询参数等输入不参与形参注入, 须在方法内显式获取
     * - 键名为数字的参数跳过(如通配捕获的多段拼接值)
     *
     * @access private
     * @return array
     */
    private function controllerArgs(): array {
        $args=$this->request->attributes();
        foreach($args as $k=>$v)
            if(is_numeric($k))
                unset($args[$k]);
        return $args;
    }

    /**
     * 由处理器推断路由上下文
     *
     * - 控制器类位于 `app\{app}\controller\{Controller}` 时按命名空间推断
     *
     * @access private
     * @param mixed $handler 处理器
     * @return array<string,mixed>
     */
    private static function handlerRouteInfo(mixed $handler): array {
        $class=is_array($handler)?($handler[0]??null):null;
        $method=is_array($handler)?($handler[1]??null):null;
        if(is_string($class)&&preg_match('/^app\\\\([^\\\\]+)\\\\controller\\\\([^\\\\]+)$/',$class,$matches))
            return array(
                'app'=>lcfirst($matches[1]),
                'controller'=>$matches[2],
                'method'=>$method
            );
        return array(
            'app'=>null,
            'controller'=>null,
            'method'=>$method
        );
    }

    /**
     * 解码路径参数
     *
     * @access private
     * @param array<string,string> $params 路径参数
     * @return array<string,string>
     */
    private static function decodeParams(array $params): array {
        foreach($params as $k=>$v)
            $params[$k]=urldecode((string)$v);
        return $params;
    }

}
