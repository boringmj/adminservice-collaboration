<?php

namespace bash;

abstract class Route {

    public $uri;

    /**
     * 获取路由路径组
     * 
     * @access private
     * @return array
     */
    private function route() {
        $uri=$_SERVER['REQUEST_URI'];
        $uri=explode("?", $uri);
        $uri=$uri[0];
        $uri=explode("/", $uri);
        array_shift($uri);
        $uri=array_values($uri);
        return $uri;
    }

    /**
     * 构造方法
     * 
     * @access public
     * @return Route
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
    final public function init() {
        $this->uri=$this->route();
        return $this;
    }

    /**
     * 获取路由路径组
     * 
     * @access public
     * @return array
     */
    final public function get() {
        return $this->uri;
    }

    /**
     * 通过路由路径组加载控制器
     */
    abstract public function load();
}

?>