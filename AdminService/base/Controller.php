<?php

namespace base;

use base\Request;
use base\View;
use base\Route;
use AdminService\Config;

/**
 * 控制器基类
 * 
 * @access public
 * @abstract
 * @package base
 * @version 1.0.1
 */
abstract class Controller {

    /**
     * 请求对象
     */
    private Request $request;

    /**
     * 视图对象
     */
    private View $view;

    /**
     * 路由对象
     */
    private Route $route;

    /**
     * 构造方法
     * 
     * @access public
     * @param Request $request 请求对象
     * @param View $view 视图对象
     * @param Route $route 路由对象
     * @return void
     */
    final public function __construct(Request $request,View $view,Route $route) {
        $this->request=$request;
        $this->view=$view;
        $this->route=$route;
    }

    /**
     * 获取参数
     * 
     * @access public
     * @param int|string $param 参数
     * @param mixed $default 默认值
     * @return mixed
     */
    final public function param(int|string $param,mixed $default=null): mixed {
        return $this->request::get($param,$default);
    }

    /**
     * 设置Header
     * 
     * @access public
     * @param string $name 名称
     * @param string $value 值
     * @return void
     */
    final public function header(string $name,string $value): void {
        $this->request::setHeader($name,$value);
    }

    /**
     * 设置返回的数据类型
     * 
     * @access public
     * @param string $type 数据类型(html|json,default:html)
     * @return void
     */
    final public function type(string $type): void {
        $this->request::setReturnType($type);
    }

    /**
     * 显示模板
     * 
     * @access public
     * @param string $template 模板
     * @param array $data 数据
     * @return string
     */
    final public function view(string $template,array $data=array()): string {
        $route=$this->route->getRouteInfo();
        $template=Config::get('app.path').'/'.$route['app'].'/view'.'/'.$template.'.html';
        $this->view->init($template,$data);
        return $this->view->render();
    }

}

?>