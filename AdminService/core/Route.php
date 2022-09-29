<?php

namespace AdminService;

use bash\Route as BashRoute;

class Route extends BashRoute {
    /**
     * 通过路由路径组返回控制器
     * 
     * access public
     * @param array $route_info 路由信息
     * @return boolen
     */
    public function load(array $route_info=array()) {
        if(empty($route_info))
            $route_info=$this->getRouteInfo();
        $controller_path=__DIR__.'/../app/'.$route_info['app']."/"."controller/".$route_info['controller'].'.php';
        if (file_exists($controller_path)) {
            require_once $controller_path;
            $controller_name='app\\'.$route_info['app'].'\\controller\\'.$route_info['controller'];
            $controller=new $controller_name();
            if(method_exists($controller,$route_info['action'])) {
                $controller->{$route_info['action']}();
                return true;
            }
            else
                return false;
            return true;
        } else {
            return false;
        }
    }

    /**
     * 通过路由路径组返回路由信息(调用该方法会自动初始化路由信息)
     * 
     * access public
     * @return boolen
     */
    public function getRouteInfo() {
        // 如果没有数据则要求进行初始化
        if(empty($this->uri))
            $this->init();
        return array(
            "app"=>isset($this->uri[0])?$this->uri[0]:"Index",
            "controller"=>isset($this->uri[1])?$this->uri[1]:"Index",
            "action"=>isset($this->uri[2])?$this->uri[2]:"index",
            "params"=>array_slice($this->uri,3)
        );
    }
}