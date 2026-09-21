<?php

namespace AdminService;

use base\Response;
use base\Route as BaseRoute;
use AdminService\Router\Pipeline;
use AdminService\Router\RouteItem;
use AdminService\Router\Router;
use ReflectionException;

use function array_merge;
use function array_slice;
use function class_exists;
use function count;
use function explode;
use function file_exists;
use function implode;
use function in_array;
use function is_array;
use function is_callable;
use function is_dir;
use function is_file;
use function is_numeric;
use function is_null;
use function is_string;
use function lcfirst;
use function method_exists;
use function preg_match;
use function ucfirst;
use function urldecode;

/**
 * 路由协调器
 *
 * - 显式路由优先, 未命中时按配置决定是否回落约定式解析
 * - 显式路由表在首次请求时装配, 之后复用
 */
final class Route extends BaseRoute {

    /**
     * 显式路由表
     * @var Router|null
     */
    private ?Router $router=null;

    /**
     * 控制器方法(约定式解析结果)
     * @var mixed
     */
    private mixed $method;

    /**
     * 运行请求
     *
     * @access public
     * @return void
     * @throws Exception|ReflectionException
     */
    public function run(): void {
        $match=$this->router()->find($this->requestMethod(),'/'.implode('/',$this->get()));
        if($match!==null) {
            $this->runRoute($match[0],$match[1]);
            return;
        }
        // 未命中显式路由: 是否回落约定式由配置决定
        if(!Config::get('route.convention_fallback',false)) {
            $this->notFound();
            return;
        }
        $this->load();
        $this->runController();
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
        $response->setStatusCode(404);
        $response->setControllerReturn('404 Not Found');
    }

    /**
     * 通过路由路径组返回控制器
     *
     * @access public
     * @param array<string,mixed> $route_info 路由信息
     * @return self
     * @throws Exception
     */
    public function load(array $route_info=array()): self {
        $this->checkInit();
        if(empty($route_info))
            $route_info=$this->getRouteInfo();
        // 判断是否符合配置文件中的路由规则(规则为空则不判断)
        if(Config::get('route.params.rule.app') && !preg_match(Config::get('route.params.rule.app'),$route_info['app']))
            throw new Exception('App parameter does not meet the rules.',-402,array(
                'rule'=>Config::get('route.params.rule.app'),
                'param'=>$route_info['app']
            ));
        if(Config::get('route.params.rule.controller') && !preg_match(Config::get('route.params.rule.controller'),$route_info['controller']))
            throw new Exception('Controller parameter does not meet the rules.',-401,array(
                'rule'=>Config::get('route.params.rule.controller'),
                'param'=>$route_info['controller']
            ));
        if(Config::get('route.params.rule.method') && !preg_match(Config::get('route.params.rule.method'),$route_info['method']))
            throw new Exception('Method parameter does not meet the rules.',-400,array(
                'rule'=>Config::get('route.params.rule.method'),
                'param'=>$route_info['method']
            ));
        $app_path=Config::get('app.path').'/'.$route_info['app'];
        if(!is_dir($app_path))
            throw new Exception('App not found.',-403,array(
                'app'=>$route_info['app']
            ));
        $controller_path=$app_path.'/'.'controller/'.$route_info['controller'].'.php';
        $controller_name='app\\'.$route_info['app'].'\\controller\\'.$route_info['controller'];
        if(file_exists($controller_path)&&class_exists($controller_name)) {
            // 转化为get参数
            $this->toGet($route_info['params']);
            // 将控制器类名存入容器
            App::setClass('Controller',$controller_name);
            $controller=App::get($controller_name);
            // 将控制器实例存入容器
            App::set('Controller',$controller);
            // 判断类方法是否存在且是否为public,且排除构造方法
            if(method_exists($controller,$route_info['method'])&&is_callable(array($controller,$route_info['method']))&&$route_info['method']!='__construct') {
                $this->method=array($controller,$route_info['method']);
                return $this;
            } else
                throw new Exception("Method is not defined.",-405,array(
                    'method'=>$route_info['method'],
                    'controller'=>$route_info['controller'],
                    'app'=>$route_info['app']
                ));
        } else
            throw new Exception("Controller not found.",-404,array(
                'controller'=>$route_info['controller'],
                'app'=>$route_info['app'],
                'path'=>$controller_path
            ));
    }

    /**
     * 通过路由路径组返回路由信息(调用此方法前请先调用 checkInit() 方法)
     *
     * @access public
     * @return array
     */
    public function getRouteInfo(): array {
        // 这里具体的路由规则将来会随着配置文件的更新而更新,所以现在先这样
        return array(
            "app"=>lcfirst(!empty($this->uri[0])?$this->uri[0]:Config::get('route.default.app')),
            "controller"=>ucfirst($this->uri[1]??Config::get('route.default.controller')),
            "method"=>lcfirst($this->uri[2]??Config::get('route.default.method')),
            "params"=>array_slice($this->uri,3)
        );
    }

    /**
     * 获取显式路由表(首次访问时装配)
     *
     * - 集中式路由文件与属性声明只解析一次, 后续请求复用同一实例
     *
     * @access private
     * @return Router
     * @throws Exception
     */
    private function router(): Router {
        if($this->router!==null)
            return $this->router;
        $this->router=new Router(Config::get('middlewares.global',array()));
        $file=Config::get('route.explicit.file');
        if(is_string($file)&&is_file($file))
            $this->router->load($file);
        $classes=Config::get('route.explicit.attributes',array());
        if(!empty($classes))
            $this->router->registerAttributes($classes);
        return $this->router;
    }

    /**
     * 执行显式路由
     *
     * - 路径参数并入 GET 参数, 与约定式的取参方式保持一致
     * - 中间件按 global → group → route → controller 顺序包裹
     *
     * @access private
     * @param RouteItem $route 命中的路由
     * @param array<string,string> $params 路径参数
     * @return void
     * @throws Exception|ReflectionException
     */
    private function runRoute(RouteItem $route,array $params): void {
        $handler=$route->getHandler();
        // 路径参数优先: 同名查询参数被覆盖
        $this->request->setGet(array_merge($this->request->getGets(),self::decodeParams($params)));
        // 写入路由上下文, 供 App::getAppName 等继续可用
        App::setData('route_info',self::handlerRouteInfo($handler));
        $middlewares=array_merge($route->getMiddlewares(),Config::get('middlewares.controller',array()));
        $response=App::get(Response::class);
        (new Pipeline($middlewares))->then(function() use ($handler,$response): void {
            $response->setControllerReturn($this->callHandler($handler));
        });
    }

    /**
     * 执行约定式路由
     *
     * @access private
     * @return void
     * @throws Exception|ReflectionException
     */
    private function runController(): void {
        $method=$this->method;
        $middlewares=Config::get('middlewares.controller',array());
        $response=App::get(Response::class);
        (new Pipeline($middlewares))->then(function() use ($method,$response): void {
            $response->setControllerReturn(App::exec_class_function($method[0],$method[1],$this->controllerArgs()));
        });
    }

    /**
     * 调用处理器
     *
     * @access private
     * @param mixed $handler 处理器
     * @return mixed
     * @throws Exception|ReflectionException
     */
    private function callHandler(mixed $handler): mixed {
        if(is_array($handler)&&count($handler)===2) {
            // 与约定式一致: 控制器类名与实例均登记到容器
            if(is_string($handler[0])) {
                App::setClass('Controller',$handler[0]);
                $handler[0]=App::get($handler[0]);
            }
            App::set('Controller',$handler[0]);
            return App::exec_class_function($handler[0],$handler[1],$this->controllerArgs());
        }
        return App::exec_function($handler,$this->controllerArgs());
    }

    /**
     * 获取当前请求方法
     *
     * @access private
     * @return string
     */
    private function requestMethod(): string {
        return (string)$this->request->getServer('REQUEST_METHOD','GET');
    }

    /**
     * 提取控制器方法参数(仅保留键名不为数字的参数)
     *
     * @access private
     * @return array
     */
    private function controllerArgs(): array {
        $args=$this->request->getGets();
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

    /**
     * 将路由参数转换为GET参数
     *
     * @access private
     * @param array<string,mixed> $params 路由参数
     * @return void
     */
    private function toGet(array $params): void {
        $get=[];
        $config=Config::get('route.params.to_get.model');
        if(!in_array($config,array('value','list','value-list','list-value')))
            $config='list-value';
        $config_list=explode('-',$config);
        foreach($config_list as $value) {
            if($value=='value')
                // 键从0开始,逐一赋值
                foreach($params as $k=>$v)
                    $get[$k]=urldecode($v);
            else if($value=='list') {
                // 将前面的参数作为键,后面的参数作为值(没有后面的参数则为空)
                $count=count($params);
                for($i=0;$i<$count;$i+=2) {
                    // 清除不符合规则的键值对(规则为空则不清除)
                    if(empty(Config::get('route.params.rule.get'))||preg_match(Config::get('route.params.rule.get'),$params[$i])) {
                        $get[$params[$i]]=$params[$i+1]??null;
                        // 如果不为null则解码
                        if(!is_null($get[$params[$i]]))
                            $get[$params[$i]]=urldecode($get[$params[$i]]);
                    }
                }
            }
        }
        // 将GET参数存入请求体中
        $this->request->setGet($get);
    }

}
