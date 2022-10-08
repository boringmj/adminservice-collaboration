<?php

namespace bash;

abstract class Route {

    public array $uri;

    /**
     * 获取路由路径组
     * 
     * @access private
     * @return array
     */
    private function route(): array {
        $uri=$_SERVER['REQUEST_URI'];
        $uri=explode("?", $uri);
        $uri=$uri[1]??$uri[0];
        $uri=explode("/", $uri);
        array_shift($uri);
        $uri=array_values($uri);
        return $uri;
    }

    /**
     * 构造方法
     * 
     * @access public
     */
    final public function __construct() {
        $this->uri=array();
        return $this->init();
    }

    /**
     * 初始化路由
     * 
     * @access public
     * @return Route
     */
    final public function init(): Route {
        $this->uri=$this->route();
        return $this;
    }

    /**
     * 获取路由路径组
     * 
     * @access public
     * @return array
     */
    final public function get(): array {
        return $this->uri;
    }

    /**
     * 通过路由路径组加载控制器
     * 
     * @access public
     * @param array $route_info 路由路径组
     * @return array
     */
    abstract public function load(array $route_info=array()): array;
}

?>