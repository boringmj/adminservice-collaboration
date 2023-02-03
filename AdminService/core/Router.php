<?php

namespace AdminService;

use base\Router as BaseRouter;
use AdminService\Config;
use AdminService\Exception;
use AdminService\App;

final class Router extends BaseRouter {

    /**
     * 控制器方法
     * @var mixed
     */
    private mixed $method;

    /**
     * 通过路由路径组返回控制器
     * 
     * @access public
     * @param array $route_info 路由信息
     * @return self
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
        if (file_exists($controller_path)&&class_exists($controller_name)) {
            // 将控制器类名存入容器
            App::setClass('Controller',$controller_name);
            $controller=new $controller_name();
            // 将控制器实例存入容器
            App::set('Controller',$controller);
            // 判断类方法是否存在且是否为public,且排除构造方法
            if(method_exists($controller,$route_info['method'])&&is_callable(array($controller,$route_info['method']))&&$route_info['method']!='__construct') {
                $this->method=array($controller,$route_info['method']);
                // 转化为get参数
                $this->toGet($route_info['params']);
                $this->request->paramsInit();
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
     * 开始运行控制器(如果没有加载路由则会自动加载)
     * 
     * @access public
     * @return mixed
     */
    public function run(): mixed {
        // 先判断是否已经加载 load() 方法
        if(empty($this->method))
            $this->load();
        $method=$this->method;
        //return $method();
        return App::autoInject($method[0],$method[1]);
    }

    /**
     * 将路由参数转换为GET参数
     * 
     * @access private
     * @param array $params 路由参数
     * @return void
     */
    private function toGet($params): void {
        $config=Config::get('route.params.toget.model');
        if(!in_array($config,array('value','list','value-list','list-value')))
            $config='list-value';
        $config_list=explode('-',$config);
        foreach($config_list as $value) {
            if($value=='value')
                // 键从0开始,逐一赋值
                foreach($params as $k=>$v)
                    $_GET[$k]=$v;
            else if($value=='list') {
                // 将前面的参数作为键,后面的参数作为值(没有后面的参数则为空)
                $count=count($params);
                for($i=0;$i<$count;$i+=2) {
                    // 清除不符合规则的键值对(规则为空则不清除)
                    if(empty(Config::get('route.params.rule.get')) || preg_match(Config::get('route.params.rule.get'),$params[$i]))
                        $_GET[$params[$i]]=$params[$i+1]??null;
                }
            }
        }
    }

}

?>