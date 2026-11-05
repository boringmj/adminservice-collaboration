<?php

namespace AdminService\Router;

use base\Attribute\ApiResource;
use base\Attribute\Method;
use base\Attribute\Resource;
use base\Attribute\Route;
use base\Attribute\RouteGroup;
use base\RoutingInterface;
use AdminService\ContextStorage;
use AdminService\Exception;
use AdminService\Pipeline;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionException;
use ReflectionMethod;

use function array_intersect;
use function array_merge;
use function array_pop;
use function class_exists;
use function count;
use function explode;
use function gettype;
use function implode;
use function in_array;
use function is_array;
use function is_callable;
use function is_file;
use function is_string;
use function ltrim;
use function range;
use function rawurldecode;
use function rtrim;
use function sort;
use function substr_count;
use function spl_object_id;
use function str_contains;
use function str_replace;
use function strtoupper;
use function trim;
use function usort;

/**
 * 路由注册与匹配
 *
 * - 负责路由注册(含分组、资源、属性声明)、匹配与 URL 反向生成
 * - 匹配先静态路径后含参路径, 避免动态路由抢先命中
 * - 匹配与生成均不修改注册表, 同一实例可在多请求间复用
 */
final class Router implements RoutingInterface {

    /**
     * resource() 标准动作及其注册顺序
     * @var array<string>
     */
    private const RESOURCE_ACTIONS=array('index','create','store','show','edit','update','destroy');

    /**
     * apiResource() 标准动作及其注册顺序
     * @var array<string>
     */
    private const API_RESOURCE_ACTIONS=array('index','store','show','update','destroy');

    /**
     * 已注册路由
     * @var array<RouteItem>
     */
    private array $routes=array();

    /**
     * 缓存的编译路由数据(缓存导入时使用)
     * @var array<array<string,mixed>>
     */
    private array $route_data=array();

    /**
     * 是否处于路由数据未实例化状态
     * @var bool
     */
    private bool $lazy=false;

    /**
     * 排序后的缓存路由索引
     * @var array<int>|null
     */
    private ?array $sorted_data=null;

    /**
     * 路由名索引(惰性构建, 注册后失效)
     * @var array<string,RouteItem>|null
     */
    private ?array $named=null;

    /**
     * 缓存路由的命名索引
     * @var array<string,int>|null
     */
    private ?array $named_data=null;

    /**
     * 按具体度排序后的路由(惰性构建, 注册后失效)
     * @var array<RouteItem>|null
     */
    private ?array $sorted_cache=null;

    /**
     * 静态路径索引(惰性构建, 注册后失效)
     * @var array<string,array<string,RouteItem>>|null
     */
    private ?array $static_index=null;

    /**
     * 缓存静态路径索引
     * @var array<string,array<string,int>>|null
     */
    private ?array $static_index_data=null;

    /**
     * 各执行流最近命中的路由
     *
     * - 键为请求上下文 / Fiber / 主流程标识
     * - 共享 Router 只保留只读路由表，请求态不串流
     * @var array<string,RouteItem>
     */
    private array $current_by_context=array();

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
     * 注册 GET 路由
     *
     * @access public
     * @param string $path 路由路径
     * @param mixed $handler 处理器
     * @return RouteItem
     * @throws Exception
     */
    public function get(string $path,mixed $handler): RouteItem {
        return $this->add(array(Method::GET),$path,$handler);
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
        return $this->add(array(Method::POST),$path,$handler);
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
        return $this->add(array(Method::PUT),$path,$handler);
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
        return $this->add(array(Method::PATCH),$path,$handler);
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
        return $this->add(array(Method::DELETE),$path,$handler);
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
        return $this->add(array(Method::OPTIONS),$path,$handler);
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
        return $this->add(array(Method::ANY),$path,$handler);
    }

    /**
     * 注册指定方法的路由
     *
     * @access public
     * @param array<string|Method> $methods 请求方法(枚举 / 字符串)
     * @param string $path 路由路径
     * @param mixed $handler 处理器
     * @return RouteItem
     * @throws Exception
     * @throws \InvalidArgumentException 请求方法不是非空字符串或 Method 枚举
     */
    public function match(array $methods,string $path,mixed $handler): RouteItem {
        return $this->add($methods,$path,$handler);
    }

    /**
     * 注册一条路由
     *
     * @access public
     * @param array<string|Method> $methods 请求方法(枚举 / 字符串)
     * @param string $path 路由路径
     * @param mixed $handler 处理器
     * @return RouteItem
     * @throws Exception 与已注册路由冲突
     * @throws \InvalidArgumentException 请求方法不是非空字符串或 Method 枚举
     */
    public function add(array $methods,string $path,mixed $handler): RouteItem {
        if($this->lazy)
            $this->instantiateAllRoutes();
        $route=new RouteItem($methods,self::joinPath($this->prefix(),$path),$handler,$this->groupMiddlewareEntries());
        $this->assertNoConflict($route);
        $this->routes[]=$route;
        $this->named=null;
        $this->sorted_cache=null;
        $this->static_index=null;
        $this->static_index_data=null;
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
        $prefix=trim((string)($attributes['prefix']??''),'/');
        $this->prefixStack[]=$prefix===''?'':'/'.$prefix;
        $this->middlewareStack[]=Pipeline::normalize($attributes['middleware']??array());
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
     * - 默认按控制器已实现的 public 标准动作自动裁剪, 未实现的动作跳过
     * - `$only` 非空时按白名单注册, 并校验所列动作均存在且为 public
     * - `$except` 非空时从全量标准动作中排除, 并校验剩余动作均存在且为 public
     * - `$only` 与 `$except` 不能同时非空; 过滤后的动作按标准顺序注册
     * - 标准动作与顺序: index/create/store/show/edit/update/destroy
     * - `{id}` 是这里写入路径模板的占位符, 由请求路径匹配后按名注入, 不是额外全局参数
     * - 路由名以完整路径(含当前分组前缀)的 `/` 替换为 `.` 作为前缀, 再追加动作名;
     *   如分组前缀 `/index` 下注册 `/users`, `show` 路由名为 `index.users.show`
     * - 根资源 `/` 的路由名不加前导点, 直接使用动作名(如 `index`)
     * - 路径、请求方法冲突由 `add()` 校验; 路由名唯一性由 `assertNamesUnique()` 在装配后统一校验
     *
     * @access public
     * @param string $path 资源路径
     * @param class-string $controller 控制器类名
     * @param array<string> $only 仅注册的动作(白名单)
     * @param array<string> $except 从全量标准动作中排除的动作
     * @return void
     * @throws Exception
     */
    public function resource(string $path,string $controller,array $only=array(),array $except=array()): void {
        $actions=$this->resolveResourceActions($controller,self::RESOURCE_ACTIONS,$only,$except);
        $this->registerResourceRoutes($path,$controller,$actions);
    }

    /**
     * 注册 RESTful API 资源路由
     *
     * - 默认按控制器已实现的 public 标准动作自动裁剪, 未实现的动作跳过
     * - `$only` / `$except` 语义与 {@see resource()} 一致, 标准动作集合不含 create/edit
     * - 标准动作与顺序: index/store/show/update/destroy
     * - 不含 create/edit 表单页
     * - `{id}` 占位符与路由名规则同 {@see resource()}
     *
     * @access public
     * @param string $path 资源路径
     * @param class-string $controller 控制器类名
     * @param array<string> $only 仅注册的动作(白名单)
     * @param array<string> $except 从全量标准动作中排除的动作
     * @return void
     * @throws Exception
     */
    public function apiResource(string $path,string $controller,array $only=array(),array $except=array()): void {
        $actions=$this->resolveResourceActions($controller,self::API_RESOURCE_ACTIONS,$only,$except);
        $this->registerResourceRoutes($path,$controller,$actions);
    }

    /**
     * 解析资源最终动作集合
     *
     * - 默认模式按控制器已有 public 标准动作裁剪; 同名方法非 public 时直接报错
     * - `$only` / `$except` 模式只校验最终保留的动作
     *
     * @access private
     * @param class-string $controller 控制器类名
     * @param array<string> $standard_actions 当前资源类型的标准动作(固定顺序)
     * @param array<string> $only 白名单
     * @param array<string> $except 黑名单
     * @return array<string>
     * @throws Exception
     */
    private function resolveResourceActions(string $controller,array $standard_actions,array $only,array $except): array {
        $reflection=$this->reflectClass($controller);
        if(!$reflection->isInstantiable())
            throw new Exception('Route resource class must be instantiable.',-418,array(
                'class'=>$controller
            ));
        if($only!==array()&&$except!==array())
            throw new Exception('Route resource cannot specify both "only" and "except".',-418,array(
                'class'=>$controller
            ));
        $only=$this->normalizeResourceActions($only,$standard_actions);
        $except=$this->normalizeResourceActions($except,$standard_actions);
        if($only!==array()) {
            $this->assertResourceActions($reflection,$only);
            return $only;
        }
        if($except!==array()) {
            $actions=array();
            foreach($standard_actions as $action) {
                if(!in_array($action,$except,true))
                    $actions[]=$action;
            }
            if($actions===array())
                throw new Exception('Route resource has no actions to register.',-418,array(
                    'class'=>$controller
                ));
            $this->assertResourceActions($reflection,$actions);
            return $actions;
        }
        $actions=array();
        foreach($standard_actions as $action) {
            if(!$reflection->hasMethod($action))
                continue;
            $this->assertResourceAction($reflection,$action);
            $actions[]=$action;
        }
        if($actions===array())
            throw new Exception('Route resource has no actions to register.',-418,array(
                'class'=>$controller
            ));
        return $actions;
    }

    /**
     * 反射控制器类
     *
     * @access private
     * @param class-string $class 类名
     * @return ReflectionClass<object>
     * @throws Exception 类不存在
     */
    private function reflectClass(string $class): ReflectionClass {
        try {
            return new ReflectionClass($class);
        } catch(ReflectionException) {
            throw new Exception('Class "'.$class.'" not found.',-418,array(
                'class'=>$class
            ));
        }
    }

    /**
     * 校验并归一化资源动作过滤列表
     *
     * @access private
     * @param array<string> $actions 调用方传入的过滤列表
     * @param array<string> $standard_actions 当前资源类型的标准动作
     * @return array<string>
     * @throws Exception 存在非标准动作时抛出
     */
    private function normalizeResourceActions(array $actions,array $standard_actions): array {
        $selected=array();
        foreach($actions as $action) {
            if(!is_string($action)||$action===''||!in_array($action,$standard_actions,true))
                throw new Exception('Invalid route resource action "'.(is_string($action)?$action:gettype($action)).'".',-418,array(
                    'action'=>$action,
                    'allowed'=>$standard_actions
                ));
            $selected[$action]=true;
        }
        $normalized=array();
        foreach($standard_actions as $action) {
            if(isset($selected[$action]))
                $normalized[]=$action;
        }
        return $normalized;
    }

    /**
     * 校验一组资源动作均存在且为 public
     *
     * @access private
     * @param ReflectionClass<object> $reflection 控制器反射
     * @param array<string> $actions 动作列表
     * @return void
     * @throws Exception
     */
    private function assertResourceActions(ReflectionClass $reflection,array $actions): void {
        foreach($actions as $action)
            $this->assertResourceAction($reflection,$action);
    }

    /**
     * 校验单个资源动作存在且为 public
     *
     * @access private
     * @param ReflectionClass<object> $reflection 控制器反射
     * @param string $action 动作名
     * @return void
     * @throws Exception
     */
    private function assertResourceAction(ReflectionClass $reflection,string $action): void {
        if(!$reflection->hasMethod($action)||!$reflection->getMethod($action)->isPublic())
            throw new Exception('Route resource action "'.$action.'" is missing or not public.',-418,array(
                'class'=>$reflection->getName(),
                'action'=>$action
            ));
    }

    /**
     * 按最终动作集合注册资源路由
     *
     * @access private
     * @param string $path 资源路径
     * @param class-string $controller 控制器类名
     * @param array<string> $actions 最终动作集合(已按标准顺序归一化)
     * @return void
     * @throws Exception
     */
    private function registerResourceRoutes(string $path,string $controller,array $actions): void {
        $base='/'.trim($path,'/');
        $name=$this->resourceNamePrefix($base);
        foreach($actions as $action) {
            switch($action) {
                case 'index':
                    $route=$this->get($base,array($controller,'index'));
                    break;
                case 'create':
                    $route=$this->get($base.'/create',array($controller,'create'));
                    break;
                case 'store':
                    $route=$this->post($base,array($controller,'store'));
                    break;
                case 'show':
                    $route=$this->get($base.'/{id}',array($controller,'show'));
                    break;
                case 'edit':
                    $route=$this->get($base.'/{id}/edit',array($controller,'edit'));
                    break;
                case 'update':
                    $route=$this->match(array(Method::PUT,Method::PATCH),$base.'/{id}',array($controller,'update'));
                    break;
                case 'destroy':
                    $route=$this->delete($base.'/{id}',array($controller,'destroy'));
                    break;
                default:
                    throw new Exception('Invalid route resource action "'.$action.'".',-418,array(
                        'action'=>$action
                    ));
            }
            $route->name($this->resourceRouteName($name,$action));
        }
    }

    /**
     * 生成资源路由名前缀
     *
     * - 以完整路径(含当前分组前缀)为准, 保证路径与路由名前缀一致
     * - 根路径 `/` 返回空串, 由调用方直接使用动作名
     *
     * @access private
     * @param string $path 资源根路径
     * @return string
     */
    private function resourceNamePrefix(string $path): string {
        return trim(str_replace('/','.',self::joinPath($this->prefix(),$path)),'.');
    }

    /**
     * 生成资源动作路由名
     *
     * @access private
     * @param string $prefix 资源名前缀
     * @param string $action 动作名
     * @return string
     */
    private function resourceRouteName(string $prefix,string $action): string {
        return $prefix===''?$action:$prefix.'.'.$action;
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
     * 从控制器属性注册路由
     *
     * - 仅扫描传入的类, 类的收集由调用方决定
     * - 类上的 `#[RouteGroup]` 提供统一前缀与中间件, 方法上的 `#[Route]` 声明子路径与命名
     * - 方法型短语糖 `#[Get]` / `#[Post]` 等位于 `base\Attribute\RouteMethod`, 继承 `#[Route]` 并一并识别
     * - 类上的 `#[Resource]` / `#[ApiResource]` 复用资源路由注册, 并与 `#[RouteGroup]` 组合
     * - 资源注解可重复声明, 按类上的声明顺序注册; 注解本身不随继承传递
     * - 带资源声明的控制器必须可实例化; 动作按注解的 only/except 或默认自动裁剪解析, 装配期校验
     *
     * @access public
     * @param array<class-string> $classes 控制器类名列表
     * @return void
     * @throws Exception
     * @throws \InvalidArgumentException 请求方法不是非空字符串或 Method 枚举
     */
    public function registerAttributes(array $classes): void {
        foreach($classes as $class) {
            if(!is_string($class))
                throw new Exception('Route attribute class must be a class-string.',-418,array(
                    'class'=>$class
                ));
            $reflection=$this->reflectClass($class);
            // 类级声明: 路径前缀与分组中间件
            $prefix='';
            $group_middlewares=array();
            foreach($reflection->getAttributes(RouteGroup::class) as $attribute) {
                $group=$attribute->newInstance();
                $prefix=self::joinPath($prefix,$group->getPrefix());
                $group_middlewares=array_merge($group_middlewares,$group->getMiddlewares());
            }
            // 类级资源声明: 按类属性声明顺序收集, 允许同一控制器声明多个资源根
            $resources=array();
            foreach($reflection->getAttributes() as $attribute) {
                $attribute_class=$attribute->getName();
                if($attribute_class===Resource::class||$attribute_class===ApiResource::class)
                    $resources[]=$attribute->newInstance();
            }
            if($resources!==array()) {
                if(!$reflection->isInstantiable())
                    throw new Exception('Route resource class must be instantiable.',-418,array(
                        'class'=>$class
                    ));
                $attributes=array();
                if($prefix!=='')
                    $attributes['prefix']=$prefix;
                if($group_middlewares!==array())
                    $attributes['middleware']=$group_middlewares;
                $this->group($attributes,function(Router $router) use ($resources,$class): void {
                    foreach($resources as $resource) {
                        if($resource instanceof ApiResource)
                            $router->apiResource($resource->getPath(),$class,$resource->getOnly(),$resource->getExcept());
                        else
                            $router->resource($resource->getPath(),$class,$resource->getOnly(),$resource->getExcept());
                    }
                });
            }
            foreach($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                foreach($method->getAttributes(Route::class,ReflectionAttribute::IS_INSTANCEOF) as $attribute) {
                    $route=$attribute->newInstance();
                    $item=$this->add(array($route->getMethod()),self::joinPath($prefix,$route->getPath()),array($class,$method->getName()));
                    $item->bind($route->getBindings());
                    // 类级 #[RouteGroup] 的中间件归入分组层, 路由层只放 #[Route] 声明的
                    if($group_middlewares!==array())
                        $item->groupMiddleware($group_middlewares);
                    $item->middleware($route->getMiddlewares());
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
        // 未命中时 current() 必须回到 null: 否则调用方会读到上一次请求/上一次匹配的路由
        $this->resetCurrent();
        $method=strtoupper($method);
        if($this->lazy)
            return $this->findLazy($method,$uri);
        $static_index=$this->staticIndex();
        foreach(self::uriCandidates($uri) as $candidate) {
            $static=$static_index[$candidate]??null;
            if($static===null)
                continue;
            $route=$static[$method]??($static['*']??null);
            if($route!==null) {
                $this->setCurrent($route);
                return array($route,array());
            }
        }
        $match=$this->findDynamic($method,$uri);
        if($match!==null)
            $this->setCurrent($match[0]);
        return $match;
    }

    /**
     * 匹配动态路由
     *
     * - HEAD 先找显式 HEAD / 不限方法路由, 再回落到 GET
     *
     * @access private
     * @param string $method 请求方法
     * @param string $uri 请求路径
     * @return array{0:RouteItem,1:array<string,string>}|null
     */
    private function findDynamic(string $method,string $uri): ?array {
        $fallback=null;
        foreach($this->sortedRoutes() as $route) {
            if($route->isStatic())
                continue;
            if(!$route->matchesMethod($method))
                continue;
            $params=null;
            foreach(self::uriCandidates($uri) as $candidate) {
                $params=$route->matchUri($candidate);
                if($params!==null)
                    break;
            }
            if($params===null)
                continue;
            $methods=$route->getMethods();
            if($method==='HEAD'
                &&!in_array('HEAD',$methods,true)
                &&!in_array('*',$methods,true)) {
                if($fallback===null)
                    $fallback=array($route,$params);
                continue;
            }
            return array($route,$params);
        }
        return $fallback;
    }

    /**
     * 缓存路由匹配
     *
     * - 静态索引直接定位; 动态路由按排序索引遍历
     * - 只有最终命中的路由才构造 RouteItem
     *
     * @access private
     * @param string $method 请求方法
     * @param string $uri 请求路径
     * @return array{0:RouteItem,1:array<string,string>}|null
     */
    private function findLazy(string $method,string $uri): ?array {
        $static_index=$this->staticIndexData();
        foreach(self::uriCandidates($uri) as $candidate) {
            $static=$static_index[$candidate]??null;
            if($static===null)
                continue;
            $index=$static[$method]??($static['*']??null);
            if($index!==null) {
                $route=$this->instantiateRoute($index);
                $this->setCurrent($route);
                return array($route,array());
            }
        }
        $fallback=null;
        foreach($this->sortedRouteIndices() as $index) {
            $data=$this->route_data[$index];
            if(RouteItem::isStaticData($data['params']??array()))
                continue;
            if(!RouteItem::matchesMethodData($data['methods']??array(),$method))
                continue;
            $params=null;
            foreach(self::uriCandidates($uri) as $candidate) {
                $params=RouteItem::matchUriData((string)($data['pattern']??''),$data['params']??array(),$candidate);
                if($params!==null)
                    break;
            }
            if($params===null)
                continue;
            $methods=$data['methods']??array();
            if($method==='HEAD'
                &&!in_array('HEAD',$methods,true)
                &&!in_array('*',$methods,true)) {
                if($fallback===null)
                    $fallback=array($index,$params);
                continue;
            }
            $route=$this->instantiateRoute($index);
            $this->setCurrent($route);
            return array($route,$params);
        }
        if($fallback!==null) {
            $route=$this->instantiateRoute($fallback[0]);
            $this->setCurrent($route);
            return array($route,$fallback[1]);
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
        if($this->lazy)
            return $this->allowedMethodsLazy($uri);
        $methods=array();
        foreach($this->routes as $route) {
            $candidates=self::uriCandidates($uri);
            $matched=false;
            foreach($candidates as $candidate) {
                if($route->matchUri($candidate)!==null) {
                    $matched=true;
                    break;
                }
            }
            if(!$matched)
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
     * 缓存路由的 405 方法汇总
     *
     * @access private
     * @param string $uri 请求路径
     * @return array<string>
     */
    private function allowedMethodsLazy(string $uri): array {
        $methods=array();
        foreach($this->route_data as $data) {
            $params=$data['params']??array();
            $candidates=self::uriCandidates($uri);
            $matched=false;
            foreach($candidates as $candidate) {
                if(RouteItem::matchUriData((string)($data['pattern']??''),$params,$candidate)!==null) {
                    $matched=true;
                    break;
                }
            }
            if(!$matched)
                continue;
            $allowed=RouteItem::allowedMethodsData($data['methods']??array());
            if($allowed===array())
                return array();
            foreach($allowed as $method)
                if(!in_array($method,$methods,true))
                    $methods[]=$method;
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
        return $this->current_by_context[$this->currentKey()]??null;
    }

    /**
     * 清除最近命中的路由
     *
     * - 请求结束或常驻 worker 回收请求态时调用
     * - 只清当前执行流; 路由表本身仍可复用
     *
     * @access public
     * @return void
     */
    public function resetCurrent(): void {
        unset($this->current_by_context[$this->currentKey()]);
    }

    /**
     * 写入当前执行流最近命中的路由
     *
     * @access private
     * @param RouteItem $route 路由
     * @return void
     */
    private function setCurrent(RouteItem $route): void {
        $this->current_by_context[$this->currentKey()]=$route;
    }

    /**
     * 当前执行流标识
     *
     * @access private
     * @return string
     */
    private function currentKey(): string {
        $context=ContextStorage::current();
        if($context!==null)
            return 'context:'.spl_object_id($context);
        if(class_exists(\Fiber::class,false)&&($fiber=\Fiber::getCurrent())!==null)
            return 'fiber:'.spl_object_id($fiber);
        return 'main';
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
        if($this->lazy)
            $this->nameIndexData();
        else
            $this->nameIndex();
    }

    /**
     * 导出已排序的路由表
     *
     * - 懒加载模式返回 `import()` 时规范化后的路由数据, 不实例化 `RouteItem`, 也不补齐可选键;
     *   返回键集合取决于导入数据, 需要固定结构时先调用 `getRoutes()` 或使用 eager 导入
     * - 非懒加载模式返回 `RouteItem::export()` 的固定结构
     *
     * @access public
     * @return array<array<string,mixed>>
     * @throws Exception 非懒加载模式下路由项无法导出
     */
    public function export(): array {
        if($this->lazy)
            return $this->routeDataInOrder();
        $out=array();
        foreach($this->sortedRoutes() as $route)
            $out[]=$route->export();
        return $out;
    }

    /**
     * 从缓存数组恢复路由表
     *
     * @access public
     * @param array<array<string,mixed>> $routes 路由数据(按排序顺序)
     * @param bool $sorted 数据是否已按具体度排序
     * @param bool $lazy 是否延迟构造 RouteItem
     * @return void
     * @throws Exception 路由数据非法或路由名重复
     */
    public function import(array $routes,bool $sorted=true,bool $lazy=true): void {
        // 懒加载数据会直接参与匹配, 必须先按构造 RouteItem 的规则规范化
        $this->routes=array();
        $this->route_data=array();
        $this->lazy=false;
        $this->sorted_data=null;
        $this->named=null;
        $this->named_data=null;
        $this->current_by_context=array();
        $this->prefixStack=array();
        $this->middlewareStack=array();
        $this->sorted_cache=null;
        $this->static_index=null;
        $this->static_index_data=null;
        if($lazy) {
            foreach($routes as $data) {
                if(!is_array($data))
                    throw new Exception('Invalid compiled route.',-423);
                $this->route_data[]=RouteItem::normalizeData($data);
            }
            $this->lazy=true;
            if($sorted)
                $this->sorted_data=$this->route_data===array()?array():range(0,count($this->route_data)-1);
        } else {
            foreach($routes as $data) {
                if(!is_array($data))
                    throw new Exception('Invalid compiled route.',-423);
                $this->routes[]=RouteItem::fromArray($data);
            }
            $this->sorted_cache=$sorted?$this->routes:null;
        }
        $this->assertNamesUnique();
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
        if($this->sorted_cache!==null)
            return $this->sorted_cache;
        $routes=$this->routes;
        usort($routes,function(RouteItem $a,RouteItem $b): int {
            if($a->isStatic()!==$b->isStatic())
                return $a->isStatic()?-1:1;
            if($a->getLiteralLength()!==$b->getLiteralLength())
                return $b->getLiteralLength()<=>$a->getLiteralLength();
            return count($a->getParamNames())<=>count($b->getParamNames());
        });
        return $this->sorted_cache=$routes;
    }

    /**
     * 静态路径索引
     *
     * @access private
     * @return array<string,array<string,RouteItem>>
     */
    private function staticIndex(): array {
        if($this->static_index!==null)
            return $this->static_index;
        $index=array();
        foreach($this->routes as $route) {
            if(!$route->isStatic())
                continue;
            $path=$route->getPath();
            $methods=$route->getMethods();
            if($methods===array()||in_array('*',$methods,true)) {
                $index[$path]['*']=$route;
                continue;
            }
            foreach($methods as $method)
                $index[$path][$method]=$route;
            // GET 只为 HEAD 兜底; 显式 HEAD 路由优先, 不能被后注册的 GET 覆盖
            if(in_array('GET',$methods,true)
                &&!in_array('HEAD',$methods,true)
                &&!isset($index[$path]['HEAD']))
                $index[$path]['HEAD']=$route;
        }
        return $this->static_index=$index;
    }

    /**
     * 缓存静态路径索引
     *
     * @access private
     * @return array<string,array<string,int>>
     */
    private function staticIndexData(): array {
        if($this->static_index_data!==null)
            return $this->static_index_data;
        $index=array();
        foreach($this->route_data as $position=>$data) {
            if(!RouteItem::isStaticData($data['params']??array()))
                continue;
            $path=(string)($data['path']??'');
            $methods=$data['methods']??array();
            if($methods===array()||in_array('*',$methods,true)) {
                $index[$path]['*']=$position;
                continue;
            }
            foreach($methods as $method)
                $index[$path][$method]=$position;
            if(in_array('GET',$methods,true)
                &&!in_array('HEAD',$methods,true)
                &&!isset($index[$path]['HEAD']))
                $index[$path]['HEAD']=$position;
        }
        return $this->static_index_data=$index;
    }

    /**
     * 缓存路由的排序索引
     *
     * @access private
     * @return array<int>
     */
    private function sortedRouteIndices(): array {
        if($this->sorted_data!==null)
            return $this->sorted_data;
        if($this->route_data===array())
            return $this->sorted_data=array();
        $indices=range(0,count($this->route_data)-1);
        usort($indices,function(int $ia,int $ib): int {
            $a=$this->route_data[$ia];
            $b=$this->route_data[$ib];
            $a_static=RouteItem::isStaticData($a['params']??array());
            $b_static=RouteItem::isStaticData($b['params']??array());
            if($a_static!==$b_static)
                return $a_static?-1:1;
            $a_length=(int)($a['literal_length']??0);
            $b_length=(int)($b['literal_length']??0);
            if($a_length!==$b_length)
                return $b_length<=>$a_length;
            return count($a['params']??array())<=>count($b['params']??array());
        });
        return $this->sorted_data=$indices;
    }

    /**
     * 按排序顺序返回缓存路由数据
     *
     * @access private
     * @return array<array<string,mixed>>
     */
    private function routeDataInOrder(): array {
        $out=array();
        foreach($this->sortedRouteIndices() as $index)
            $out[]=$this->route_data[$index];
        return $out;
    }

    /**
     * 实例化单条路由
     *
     * @access private
     * @param int $index 路由数据索引
     * @return RouteItem
     */
    private function instantiateRoute(int $index): RouteItem {
        return RouteItem::fromArray($this->route_data[$index]);
    }

    /**
     * 实例化全部路由
     *
     * @access private
     * @return void
     */
    private function instantiateAllRoutes(): void {
        if(!$this->lazy)
            return;
        $routes=array();
        foreach($this->route_data as $data)
            $routes[]=RouteItem::fromArray($data);
        $this->routes=$routes;
        $this->route_data=array();
        $this->lazy=false;
        $this->sorted_data=null;
        $this->sorted_cache=null;
        $this->named=null;
        $this->named_data=null;
        $this->static_index=null;
        $this->static_index_data=null;
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
        if($this->lazy) {
            $index=$this->nameIndexData();
            if(!isset($index[$name]))
                throw new Exception('Route name not found.',-411,array(
                    'name'=>$name
                ));
            return $this->instantiateRoute($index[$name])->buildUri($params);
        }
        $index=$this->nameIndex();
        if(!isset($index[$name]))
            throw new Exception('Route name not found.',-411,array(
                'name'=>$name
            ));
        return $index[$name]->buildUri($params);
    }

    /**
     * 判断路由名是否已注册
     *
     * @access public
     * @param string $name 路由名
     * @return bool
     * @throws Exception 路由名重复
     */
    public function has(string $name): bool {
        if($this->lazy)
            return isset($this->nameIndexData()[$name]);
        return isset($this->nameIndex()[$name]);
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
     * 构建缓存路由的命名索引
     *
     * @access private
     * @return array<string,int>
     * @throws Exception 存在重名
     */
    private function nameIndexData(): array {
        if($this->named_data!==null)
            return $this->named_data;
        $index=array();
        foreach($this->route_data as $position=>$data) {
            $name=$data['name']??null;
            if(!is_string($name))
                continue;
            if(isset($index[$name]))
                throw new Exception('Duplicate route name.',-419,array(
                    'name'=>$name,
                    'path'=>(string)($data['path']??''),
                    'exists'=>(string)($this->route_data[$index[$name]]['path']??'')
                ));
            $index[$name]=$position;
        }
        return $this->named_data=$index;
    }

    /**
     * 获取已注册路由
     *
     * @access public
     * @return array<RouteItem>
     */
    public function getRoutes(): array {
        if($this->lazy)
            $this->instantiateAllRoutes();
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
     * 拼接分组前缀与路由路径
     *
     * - 两侧都允许带/不带斜杠: `/admin` + `users`、`/admin/` + `/users` 都得到 `/admin/users`
     * - 前缀为空时原样返回路径; 路径为空时返回前缀
     *
     * @access private
     * @param string $prefix 分组前缀
     * @param string $path 路由路径
     * @return string
     */
    private static function joinPath(string $prefix,string $path): string {
        if($prefix===''||$prefix==='/')
            return $path===''?'/':$path;
        if($path==='')
            return $prefix;
        return rtrim($prefix,'/').'/'.ltrim($path,'/');
    }

    /**
     * 获取当前分组栈的中间件条目(层内排序由 RouteItem 完成)
     *
     * - 请求级与控制器级中间件不在此组装: 前者在匹配前执行, 后者由分发时按处理器解析
     *
     * @access private
     * @return array<array{middleware:string|object,priority:int}>
     */
    private function groupMiddlewareEntries(): array {
        $entries=array();
        foreach($this->middlewareStack as $stack)
            $entries=array_merge($entries,$stack);
        return $entries;
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

    /**
     * 请求路径的原始与解码候选
     *
     * - 静态字面量与动态参数先按原始路径匹配, 失败后再尝试解码路径
     * - `%2F` 会改变路径分段, 保留原始 URI, 交给动态路由按原始分段处理
     * - 使用 `rawurldecode()` 而非 `urldecode()`, 保持路径中的 `+` 不是空格
     *
     * @access private
     * @param string $uri 已规范化的请求路径
     * @return array<string>
     */
    private static function uriCandidates(string $uri): array {
        if(!str_contains($uri,'%'))
            return array($uri);
        $decoded=rawurldecode($uri);
        if($decoded===$uri||substr_count($decoded,'/')!==substr_count($uri,'/'))
            return array($uri);
        return array($uri,$decoded);
    }

}
