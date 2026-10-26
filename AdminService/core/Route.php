<?php

namespace AdminService;
use AdminService\Config\Repository;
use base\Attribute\AutowireProperty;
use base\ConfigInterface;

use base\Response;
use base\Route as BaseRoute;
use base\Attribute\Middleware;
use AdminService\Router\RouteItem;
use AdminService\Router\Router;
use AdminService\Router\RouterFactory;
use ReflectionClass;
use base\RouteContextInterface;
use base\RoutingInterface;
use ReflectionException;

use function array_merge;
use function class_exists;
use function count;
use function implode;
use function in_array;
use function is_array;
use function is_numeric;
use function is_string;
use function urldecode;

/**
 * 路由协调器
 *
 * - 显式路由由路由文件与控制器 `#[Route]` 属性声明, 未命中即 404
 * - 路由协调器持有请求对象, 是**请求级对象**: 每请求新建
 * - 路由表本身由应用级 `RouterFactory` 缓存: **同一 Application/进程内**复用同一 `Router`;
 *   默认 HTTP 入口每请求新建 `Main`, 因此不会跨请求复用; `Config::setRepository()` 替换配置对象时重建
 * - 处理器与中间件声明必须与请求无关: 优先用类名; 已实例化对象不得持有请求状态; 闭包不得捕获请求对象
 */
final class Route extends BaseRoute {
    /**
     * 配置(由容器装配时注入; 直接 new 时为 null → 每次回落门面当前那份)
     * @var ConfigInterface|null
     */
    #[AutowireProperty(ConfigInterface::class)]
    private ?ConfigInterface $config=null;

    /**
     * 取配置契约实例
     *
     * @access private
     * @return ConfigInterface
     */
    private function config(): ConfigInterface {
        return $this->config??Config::repository()??new Repository(array());
    }


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
            // 自动 OPTIONS 同样属于该路径支持的方法
            if(!in_array('OPTIONS',$allowed,true))
                $allowed[]='OPTIONS';
            if($method==='OPTIONS')
                $this->options($allowed);
            else
                $this->methodNotAllowed($allowed);
            return;
        }
        $this->notFound();
    }

    /**
     * 方法不被允许时的响应
     *
     * @access private
     * @param array<string> $allowed 允许的请求方法
     * @return void
     * @throws Exception|ReflectionException
     */
    private function methodNotAllowed(array $allowed): void {
        HttpError::methodNotAllowed($allowed)->apply(App::get(Response::class));
    }

    /**
     * OPTIONS 自动应答
     *
     * - 204 仍带 `Allow` 头, 客户端据此得知可用方法
     *
     * @access private
     * @param array<string> $allowed 允许的请求方法
     * @return void
     * @throws Exception|ReflectionException
     */
    private function options(array $allowed): void {
        $response=App::get(Response::class);
        $response->header('Allow',implode(', ',$allowed));
        $response->status(204);
        $response->body('');
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
        HttpError::notFound()->apply(App::get(Response::class));
    }

    /**
     * 获取路由表(应用级缓存)
     *
     * - 优先复用应用容器里的 `RouterFactory`; 独立使用 `Route`(没有应用容器)时退回局部提供器
     * - 同一配置对象只装配一次; 配置对象被替换时由 `RouterFactory` 重建
     * - 仍登记到当前请求容器, 供运行时(控制器/视图)反向生成 URL: App::get(Router::class)->url(...)
     * - 同时以 `base\RoutingInterface` 暴露同一实例, 控制器只依赖契约
     *
     * @access private
     * @return Router
     * @throws Exception
     */
    private function router(): Router {
        if($this->router!==null)
            return $this->router;
        $factory=App::hasInstance()&&App::getInstance()->hasInstance(RouterFactory::class)
            ? App::get(RouterFactory::class)
            : new RouterFactory();
        $this->router=$factory->get($this->config());
        App::instance(Router::class,$this->router);
        App::instance(RoutingInterface::class,$this->router);
        return $this->router;
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
        $params=self::decodeParams($params);
        // 路径参数写入属性区(不并入GET): 参数检索时属性优先, 同名查询参数仍按查询参数读取
        foreach($params as $name=>$value)
            $this->request->setAttribute($name,$value);
        // 注册路由上下文(**请求级**对象): 控制器基类、视图路径推断与 App::getAppName 系列都从它取值
        App::instance(RouteContextInterface::class,RouteContext::fromHandler($handler,$params));
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
        $entries=Pipeline::normalize($this->config()->get('middlewares.controller',array()));
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
            // 控制器实例属**请求级**对象: 每请求新建;`fresh()` 会按处理器类名登记, 供控制器助手按类名取用
            if(is_string($handler[0]))
                $handler[0]=App::fresh($handler[0]);
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
