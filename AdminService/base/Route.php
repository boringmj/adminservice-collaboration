<?php

namespace base;

use base\Request;
use AdminService\Exception;

abstract class Route {

    /**
     * 请求对象
     */
    protected Request $request;

    /**
     * 路由路径组
     */
    protected array $uri;

    /**
     * 是否已经初始化
     */
    protected bool $is_init;

    /**
     * 获取路由路径组
     * 
     * @access private
     * @return array
     */
    private function route(): array {
        $uri=$_SERVER['REQUEST_URI'];
        $uri=explode("?",$uri);
        $uri=$uri[1]??$uri[0];
        $uri=explode("/",$uri);
        array_shift($uri);
        $uri=array_values($uri);
        return $uri;
    }

    /**
     * 构造方法(如果有传入请求对象则会自动初始化)
     * 
     * @access public
     * @param Request $request 请求对象
     */
    final public function __construct(?Request $request=null) {
        $this->is_init=false;
        $this->uri=array();
        if($request!==null)
            return $this->init($request);
    }

    /**
     * 初始化路由
     * 
     * @access public
     * @param Request $request 请求对象
     * @return self
     */
    final public function init(Request $request): self {
        $this->request=$request;
        $this->uri=$this->route();
        $this->is_init=true;
        return $this;
    }

    /**
     * 获取路由路径组
     * 
     * @access public
     * @return array
     */
    final public function get(): array {
        $this->checkInit();
        return $this->uri;
    }

    /**
     * 检查是否已经初始化
     * 
     * @access protected
     * @return void
     */
    protected function checkInit(): void {
        if(!$this->is_init)
            throw new Exception('Route is not initialized.',-406);
    }

    /**
     * 通过路由路径组加载控制器
     * 
     * @access public
     * @param array $route_info 路由路径组
     * @return self
     */
    abstract public function load(array $route_info=array()): self;

}

?>