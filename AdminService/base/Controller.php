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
 * @version 1.0.3
 */
abstract class Controller {

    /**
     * 请求对象
     * @var Request
     */
    protected Request $request;

    /**
     * 响应对象
     * @var Response
     */
    protected Response $response;

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
     * @param Response|null $response 响应对象
     * @param View|null $view 视图对象
     * @throws Exception
     * @throws ReflectionException
     */
    final public function __construct(
        ?Request $request=null,
        ?Response $response=null,
        ?View $view=null
    ) {
        $this->request=$request??App::get(Request::class);
        $this->response=$response??App::get(Response::class);
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
        return $this->request::getParam($param,Request::ALL_PARAM,$default);
    }

    /**
     * 设置Header
     * 
     * @access protected
     * @param string $name 名称
     * @param string $value 值
     * @return void
     */
    final protected function header(string $name,string $value): void {
        $this->response::setHeader($name,$value);
    }

    /**
     * 设置Cookie信息
     *
     * @access protected
     * @param string|array $params 参数(string时为cookie名,array时为cookie数组)
     * @param string|null $value Cookie值($params 参数为数组时此参数无效)
     * @param int|null $expire 过期时间($params 参数为数组时此参数无效)
     * @param string|null $path 路径($params 参数为数组时此参数无效)
     * @param string|null $domain 域名($params 参数为数组时此参数无效)
     * @param bool $secure 是否安全传输($params 参数为数组时此参数无效)
     * @param bool $httponly 是否仅http传输($params 参数为数组时此参数无效)
     * @return void
     */
    final protected function cookie(
        string|array $params,
        ?string $value=null,
        ?int $expire=null,
        ?string $path=null,
        ?string $domain=null,
        ?bool $secure=null,
        ?bool $httponly=null
    ): void {
        $this->response::setCookie(
            $params,
            $value,
            $expire,
            $path,
            $domain,
            $secure,
            $httponly
        );
    }

    /**
     * 设置返回的数据类型
     * 
     * @access protected
     * @param string $type 数据类型
     * @return static
     */
    final protected function type(string $type): static {
        $this->response::setContentType($type);
        return $this;
    }

    /**
     * 设置返回时的状态码
     * 
     * @access protected
     * @param int $code 状态码
     * @return static
     */
    final protected function code(int $code): static {
        $this->response::setStatusCode($code);
        return $this;
    }

    /**
     * 设置返回类型为json
     * 
     * @access protected
     * @param mixed $data 数据
     * @param int $code 状态码
     * @return mixed
     */
    final protected function json(mixed $data,int $code=200): mixed {
        $this->response::setStatusCode($code);
        return $this->response::json($data);
    }

    /**
     * 设置返回内容为html
     * 
     * @access protected
     * @param array|object|string|int|bool|null $html html内容
     * @param int $code 状态码
     * @return mixed
     */
    final protected function html(mixed $html,int $code=200): mixed {
        $this->response::setStatusCode($code);
        return $this->response::html($html);
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
        return $this->html($this->view->render(),$this->response->getStatusCode());
    }

}