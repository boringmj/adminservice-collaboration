<?php

namespace base;

use base\Request;
use base\View;
use AdminService\Config;
use AdminService\App;

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
     * @var \base\Request
     */
    private Request $request;

    /**
     * 视图对象
     * @var \base\View
     */
    private View $view;

    /**
     * 构造方法
     * 
     * @access public
     * @param \base\Request $request 请求对象
     * @param \base\View $view 视图对象
     */
    final public function __construct(?Request $request=null,?View $view=null) {
        $this->request=$request??App::get('Request');
        $this->view=$view??App::get('View');
        $this->router=$router??App::get('Router');
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
     * 显示视图
     * 
     * @access public
     * @param string|array $template 视图名称或数据(如果传入数组则为数据)
     * @param array $data 数据
     * @return string
     */
    final public function view(string|array $template=null,array $data=array()): string {
        if(is_array($template)) {
            $data=$template;
            $template=null;
        }
        if($template===null)
            $template=App::getMethodName();
        $template=Config::get('app.path').'/'.App::getAppName().'/view'.'/'.App::getControllerName().'/'.$template.'.html';
        $this->view->init($template,$data);
        return $this->view->render();
    }

}

?>