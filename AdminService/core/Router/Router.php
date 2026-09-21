<?php

namespace AdminService\Router;

use AdminService\Exception;
use ReflectionClass;
use ReflectionMethod;

use function array_intersect;
use function array_merge;
use function array_pop;
use function class_exists;
use function count;
use function explode;
use function implode;
use function in_array;
use function is_array;
use function is_callable;
use function is_file;
use function is_string;
use function sort;
use function str_replace;
use function trim;
use function usort;

/**
 * 路由注册与匹配
 *
 * - 负责路由注册(含分组、资源、属性声明)、匹配与 URL 反向生成
 * - 匹配先静态路径后含参路径, 避免动态路由抢先命中
 * - 匹配与生成均不修改注册表, 同一实例可在多请求间复用
 */
final class Router {

    /**
     * 已注册路由
     * @var array<RouteItem>
     */
    private array $routes=array();

    /**
     * 路由名索引(惰性构建, 注册后失效)
     * @var array<string,RouteItem>|null
     */
    private ?array $named=null;

    /**
     * 最近命中的路由
     * @var RouteItem|null
     */
    private ?RouteItem $current=null;

    /**
     * 全局中间件
     * @var array<string|object>
     */
    private array $middlewares;

    /**
     * 分组前缀栈
     * @var array<string>
     */
    private array $prefixStack=array();

    /**
     * 分组中间件栈
     * @var array<array<string|object>>
     */
    private array $middlewareStack=array();

    /**
     * 构造方法
     *
     * @access public
     * @param array<string|object> $middlewares 全局中间件
     */
    public function __construct(array $middlewares=array()) {
        $this->middlewares=$middlewares;
    }

    /**
     * 注册 GET 路由
     *
     * @access public
     * @param string $path 路由路径
     * @param mixed $handler 处理器
     * @return RouteItem
     * @throws Exception
     */
    public function get(string $path,mixed $handler): RouteItem {
        return $this->add(array('GET'),$path,$handler);
    }

    /**
     * 注册 POST 路由
     *
     * @access public
     * @param string $path 路由路径
     * @param mixed $handler 处理器
     * @return RouteItem
     * @throws Exception
     */
    public function post(string $path,mixed $handler): RouteItem {
        return $this->add(array('POST'),$path,$handler);
    }

    /**
     * 注册 PUT 路由
     *
     * @access public
     * @param string $path 路由路径
     * @param mixed $handler 处理器
     * @return RouteItem
     * @throws Exception
     */
    public function put(string $path,mixed $handler): RouteItem {
        return $this->add(array('PUT'),$path,$handler);
    }

    /**
     * 注册 PATCH 路由
     *
     * @access public
     * @param string $path 路由路径
     * @param mixed $handler 处理器
     * @return RouteItem
     * @throws Exception
     */
    public function patch(string $path,mixed $handler): RouteItem {
        return $this->add(array('PATCH'),$path,$handler);
    }

    /**
     * 注册 DELETE 路由
     *
     * @access public
     * @param string $path 路由路径
     * @param mixed $handler 处理器
     * @return RouteItem
     * @throws Exception
     */
    public function delete(string $path,mixed $handler): RouteItem {
        return $this->add(array('DELETE'),$path,$handler);
    }

    /**
     * 注册 OPTIONS 路由
     *
     * @access public
     * @param string $path 路由路径
     * @param mixed $handler 处理器
     * @return RouteItem
     * @throws Exception
     */
    public function options(string $path,mixed $handler): RouteItem {
        return $this->add(array('OPTIONS'),$path,$handler);
    }

    /**
     * 注册不限方法的路由
     *
     * @access public
     * @param string $path 路由路径
     * @param mixed $handler 处理器
     * @return RouteItem
     * @throws Exception
     */
    public function any(string $path,mixed $handler): RouteItem {
        return $this->add(array('*'),$path,$handler);
    }

    /**
     * 注册指定方法的路由
     *
     * @access public
     * @param array<string> $methods 请求方法
     * @param string $path 路由路径
     * @param mixed $handler 处理器
     * @return RouteItem
     * @throws Exception
     */
    public function match(array $methods,string $path,mixed $handler): RouteItem {
        return $this->add($methods,$path,$handler);
    }

    /**
     * 注册一条路由
     *
     * @access public
     * @param array<string> $methods 请求方法
     * @param string $path 路由路径
     * @param mixed $handler 处理器
     * @return RouteItem
     * @throws Exception 与已注册路由冲突
     */
    public function add(array $methods,string $path,mixed $handler): RouteItem {
        $route=new RouteItem($methods,$this->prefix().$path,$handler,$this->middlewares());
        $this->assertNoConflict($route);
        $this->routes[]=$route;
        $this->named=null;
        return $route;
    }

    /**
     * 校验路由冲突
     *
     * - 匹配形状相同且请求方法有交集即视为冲突: 「先注册者胜」会静默丢弃后注册的路由
     * - 静态与含参路径形状不同, 不算冲突(匹配时静态优先)
     *
     * @access private
     * @param RouteItem $route 待注册路由
     * @return void
     * @throws Exception 冲突时抛出
     */
    private function assertNoConflict(RouteItem $route): void {
        foreach($this->routes as $exists) {
            if($exists->getShape()!==$route->getShape())
                continue;
            if(!self::methodsIntersect($exists->getMethods(),$route->getMethods()))
                continue;
            throw new Exception('Route conflict.',-415,array(
                'path'=>$route->getPath(),
                'methods'=>$route->getMethods(),
                'exists'=>array(
                    'path'=>$exists->getPath(),
                    'methods'=>$exists->getMethods()
                )
            ));
        }
    }

    /**
     * 判断两组请求方法是否有交集(空集合与 `*` 均视为不限方法)
     *
     * @access private
     * @param array<string> $a 请求方法
     * @param array<string> $b 请求方法
     * @return bool
     */
    private static function methodsIntersect(array $a,array $b): bool {
        if($a===array()||$b===array())
            return true;
        if(in_array('*',$a,true)||in_array('*',$b,true))
            return true;
        return array_intersect($a,$b)!==array();
    }

    /**
     * 注册路由分组
     *
     * - `prefix` 依次拼接, `middleware` 依次追加
     * - 回调抛异常时分组栈同样回退
     *
     * @access public
     * @param array<string,mixed> $attributes 分组属性(prefix / middleware)
     * @param callable $callback 分组回调(接收本实例)
     * @return void
     */
    public function group(array $attributes,callable $callback): void {
        $this->prefixStack[]=isset($attributes['prefix'])?'/'.trim((string)$attributes['prefix'],'/'):'';
        $this->middlewareStack[]=self::normalizeMiddlewares($attributes['middleware']??array());
        try {
            $callback($this);
        } finally {
            array_pop($this->prefixStack);
            array_pop($this->middlewareStack);
        }
    }

    /**
     * 注册 RESTful 资源路由
     *
     * - 生成 index/create/store/show/edit/update/destroy 七个动作
     * - 路由名以路径的斜杠替换为点号后作为前缀
     *
     * @access public
     * @param string $path 资源路径
     * @param string $controller 控制器类名
     * @return void
     * @throws Exception
     */
    public function resource(string $path,string $controller): void {
        $base='/'.trim($path,'/');
        $name=trim(str_replace('/','.',$base),'.');
        $this->get($base,array($controller,'index'))->name($name.'.index');
        $this->get($base.'/create',array($controller,'create'))->name($name.'.create');
        $this->post($base,array($controller,'store'))->name($name.'.store');
        $this->get($base.'/{id}',array($controller,'show'))->name($name.'.show');
        $this->get($base.'/{id}/edit',array($controller,'edit'))->name($name.'.edit');
        $this->match(array('PUT','PATCH'),$base.'/{id}',array($controller,'update'))->name($name.'.update');
        $this->delete($base.'/{id}',array($controller,'destroy'))->name($name.'.destroy');
    }

    /**
     * 注册 RESTful API 资源路由
     *
     * - 生成 index/store/show/update/destroy 五个动作, 不含 create/edit 表单页
     * - 路由名规则同 resource()
     *
     * @access public
     * @param string $path 资源路径
     * @param string $controller 控制器类名
     * @return void
     * @throws Exception
     */
    public function apiResource(string $path,string $controller): void {
        $base='/'.trim($path,'/');
        $name=trim(str_replace('/','.',$base),'.');
        $this->get($base,array($controller,'index'))->name($name.'.index');
        $this->post($base,array($controller,'store'))->name($name.'.store');
        $this->get($base.'/{id}',array($controller,'show'))->name($name.'.show');
        $this->match(array('PUT','PATCH'),$base.'/{id}',array($controller,'update'))->name($name.'.update');
        $this->delete($base.'/{id}',array($controller,'destroy'))->name($name.'.destroy');
    }

    /**
     * 加载集中式路由文件
     *
     * - 路由文件须返回接收本实例的闭包: `return function (Router $router): void { ... };`
     * - 显式传参而非依赖 `require` 的作用域继承, 便于静态分析与阅读
     *
     * @access public
     * @param string $file 路由文件路径
     * @return void
     * @throws Exception 路由文件不存在或返回值不是可调用结构
     */
    public function load(string $file): void {
        if(!is_file($file))
            throw new Exception('Route file not found.',-410,array(
                'file'=>$file
            ));
        $definition=require $file;
        if(!is_callable($definition))
            throw new Exception('Route file must return a callable.',-417,array(
                'file'=>$file
            ));
        $definition($this);
    }

    /**
     * 从控制器方法的路由属性注册路由
     *
     * - 仅扫描传入的类, 类的收集由调用方决定
     * - 类上的 `#[RouteGroup]` 提供统一前缀与中间件, 方法上的 `#[Route]` 声明子路径与命名
     *
     * @access public
     * @param array<class-string> $classes 控制器类名列表
     * @return void
     * @throws Exception
     */
    public function registerAttributes(array $classes): void {
        foreach($classes as $class) {
            if(!is_string($class)||!class_exists($class))
                continue;
            $reflection=new ReflectionClass($class);
            // 类级声明: 路径前缀与分组中间件
            $prefix='';
            $middlewares=array();
            foreach($reflection->getAttributes(RouteGroup::class) as $attribute) {
                $group=$attribute->newInstance();
                $prefix.=$group->getPrefix();
                $middlewares=array_merge($middlewares,$group->getMiddlewares());
            }
            foreach($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                foreach($method->getAttributes(Route::class) as $attribute) {
                    $route=$attribute->newInstance();
                    $item=$this->add(array($route->getMethod()),$prefix.$route->getPath(),array($class,$method->getName()));
                    $item->middleware(array_merge($middlewares,$route->getMiddlewares()));
                    if($route->getName()!==null)
                        $item->name($route->getName());
                }
            }
        }
    }

    /**
     * 匹配请求
     *
     * @access public
     * @param string $method 请求方法
     * @param string $uri 请求路径(可含查询串)
     * @return array{0:RouteItem,1:array<string,string>}|null 未命中返回 null
     */
    public function find(string $method,string $uri): ?array {
        $uri=self::normalizeUri($uri);
        foreach($this->sortedRoutes() as $route) {
            if(!$route->matchesMethod($method))
                continue;
            $params=$route->matchUri($uri);
            if($params===null)
                continue;
            $this->current=$route;
            return array($route,$params);
        }
        return null;
    }

    /**
     * 汇总某路径允许的请求方法
     *
     * - 供 405 响应生成 `Allow` 头使用
     *
     * @access public
     * @param string $uri 请求路径
     * @return array<string> 路径未命中任何路由时返回空数组
     */
    public function allowedMethods(string $uri): array {
        $uri=self::normalizeUri($uri);
        $methods=array();
        foreach($this->routes as $route) {
            if($route->matchUri($uri)===null)
                continue;
            // 不限方法的路由可命中任何方法, 无须汇总
            if($route->allowedMethods()===array())
                return array();
            foreach($route->allowedMethods() as $method) {
                if(!in_array($method,$methods,true))
                    $methods[]=$method;
            }
        }
        sort($methods);
        return $methods;
    }

    /**
     * 获取最近命中的路由
     *
     * @access public
     * @return RouteItem|null
     */
    public function current(): ?RouteItem {
        return $this->current;
    }

    /**
     * 校验路由名唯一
     *
     * - 装配完成后调用, 让重名尽早暴露
     *
     * @access public
     * @return void
     * @throws Exception 存在重名
     */
    public function assertNamesUnique(): void {
        $this->nameIndex();
    }

    /**
     * 按具体度排序的路由列表
     *
     * - 顺序: 静态路径 → 字面量更长者 → 参数更少者, 同级保持注册顺序
     * - 避免通用路由(如 `/index/{name?}`)遮蔽更具体的同级路由(如 `/index/urlDemo/{name?}`)
     *
     * @access private
     * @return array<RouteItem>
     */
    private function sortedRoutes(): array {
        $routes=$this->routes;
        usort($routes,function(RouteItem $a,RouteItem $b): int {
            if($a->isStatic()!==$b->isStatic())
                return $a->isStatic()?-1:1;
            if($a->getLiteralLength()!==$b->getLiteralLength())
                return $b->getLiteralLength()<=>$a->getLiteralLength();
            return count($a->getParamNames())<=>count($b->getParamNames());
        });
        return $routes;
    }

    /**
     * 按路由名反向生成 URL
     *
     * @access public
     * @param string $name 路由名
     * @param array<string,mixed> $params 路径参数
     * @return string
     * @throws Exception 路由名不存在或缺少路径参数
     */
    public function url(string $name,array $params=array()): string {
        $index=$this->nameIndex();
        if(!isset($index[$name]))
            throw new Exception('Route name not found.',-411,array(
                'name'=>$name
            ));
        return $index[$name]->buildUri($params);
    }

    /**
     * 构建路由名索引
     *
     * - 惰性构建并缓存, 注册新路由后失效
     * - 重名直接报错: 否则反向生成会静默取先注册的那条
     *
     * @access private
     * @return array<string,RouteItem>
     * @throws Exception 存在重名
     */
    private function nameIndex(): array {
        if($this->named!==null)
            return $this->named;
        $index=array();
        foreach($this->routes as $route) {
            $name=$route->getName();
            if($name===null)
                continue;
            if(isset($index[$name]))
                throw new Exception('Duplicate route name.',-419,array(
                    'name'=>$name,
                    'path'=>$route->getPath(),
                    'exists'=>$index[$name]->getPath()
                ));
            $index[$name]=$route;
        }
        return $this->named=$index;
    }

    /**
     * 获取已注册路由
     *
     * @access public
     * @return array<RouteItem>
     */
    public function getRoutes(): array {
        return $this->routes;
    }

    /**
     * 获取当前分组前缀
     *
     * @access private
     * @return string
     */
    private function prefix(): string {
        return implode('',$this->prefixStack);
    }

    /**
     * 获取当前生效的中间件(全局 + 分组 + 路由由 RouteItem 持有)
     *
     * @access private
     * @return array<string|object>
     */
    private function middlewares(): array {
        $middlewares=$this->middlewares;
        foreach($this->middlewareStack as $stack)
            $middlewares=array_merge($middlewares,$stack);
        return $middlewares;
    }

    /**
     * 归一化中间件写法(单个或数组)
     *
     * @access private
     * @param mixed $middleware 中间件
     * @return array<string|object>
     */
    private static function normalizeMiddlewares(mixed $middleware): array {
        if($middleware===null||$middleware===array())
            return array();
        return is_array($middleware)?$middleware:array($middleware);
    }

    /**
     * 规范化请求路径(去掉查询串与末尾斜杠)
     *
     * @access private
     * @param string $uri 请求路径
     * @return string
     */
    private static function normalizeUri(string $uri): string {
        return '/'.trim(explode('?',$uri,2)[0],'/');
    }

}
