<?php

namespace base;

use AdminService\App;
use AdminService\Config;
use AdminService\Exception;
use \ReflectionException;

/**
 * 控制器基类
 * 
 * @access public
 * @abstract
 * @package base
 * @version 1.0.2
 */
abstract class Controller {

    /**
     * 请求对象
     * @var Request
     */
    protected Request $request;

    /**
     * 视图对象
     * @var View
     */
    protected View $view;

    /**
     * 构造方法
     *
     * @access public
     * @param Request|null $request 请求对象
     * @param View|null $view 视图对象
     * @throws Exception
     * @throws ReflectionException
     */
    final public function __construct(?Request $request=null,?View $view=null) {
        $this->request=$request??App::get(Request::class);
        $this->view=$view??App::get(View::class);
    }

    /**
     * 获取参数
     * 
     * @access protected
     * @param int|string $param 参数
     * @param mixed $default 默认值
     * @return mixed
     */
    final protected function param(int|string $param,mixed $default=null): mixed {
        return $this->request::param($param,$default);
    }

    /**
     * 设置Header
     * 
     * @access protected
     * @param string $name 名称
     * @param string $value 值
     * @return bool
     */
    final protected function header(string $name,string $value): bool {
        return $this->request::setHeader($name,$value);
    }

    /**
     * 设置返回的数据类型(需要注意,每次设置都会引入对应的Header,如果已经设置过Header,则会覆盖)
     * 
     * @access protected
     * @param string $type 数据类型(*,default:html)
     * @return self
     */
    final protected function type(string $type): self {
        $this->request::setReturnType($type);
        return $this;
    }

    /**
     * 显示视图
     *
     * @access protected
     * @param string|array|null $template 视图名称或数据(如果传入数组则为数据)
     * @param array $data 数据
     * @return string
     * @throws Exception|ReflectionException
     */
    final protected function view(null|string|array $template=null,array $data=array()): string {
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

    /**
     * 设置或获取 Cookie
     * 
     * @access protected
     * @param int|string|array $params 参数
     * @param mixed $value 值(不为空则设置)
     * @param bool $enforce 是否与 params() 方法同步
     * @return mixed
     */
    final protected function cookie(int|string|array $params,mixed $value=null,bool $enforce=false): mixed {
        return $this->request::cookieParams($params,$value,$enforce);
    }

}