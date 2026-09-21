<?php

namespace base;

use ReflectionException;

use function is_array;

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
     * 容器契约(按契约依赖, 不引用实现层)
     * @var Container|null
     */
    protected ?Container $container;

    /**
     * 配置契约(视图路径推断用)
     * @var ConfigInterface|null
     */
    protected ?ConfigInterface $config;

    /**
     * 构造方法
     *
     * - 正常路径由**容器构建**: 请求/响应/视图/容器/配置都是容器注入
     * - 手动 `new` 时须同时传入请求/响应/视图(或传入容器), 否则抛出明确异常
     *
     * @access public
     * @param Request|null $request 请求对象
     * @param Response|null $response 响应对象
     * @param View|null $view 视图对象
     * @param Container|null $container 容器契约
     * @param ConfigInterface|null $config 配置契约
     * @throws Exception
     * @throws ReflectionException
     */
    final public function __construct(
        ?Request $request=null,
        ?Response $response=null,
        ?View $view=null,
        ?Container $container=null,
        ?ConfigInterface $config=null
    ) {
        if($container===null) {
            if($request===null||$response===null||$view===null)
                throw new Exception('控制器须由容器构建(或同时传入请求/响应/视图): 请使用 App::make(控制器类)');
        } else {
            $request??=$container->get(Request::class);
            $response??=$container->get(Response::class);
            $view??=$container->get(View::class);
        }
        $this->request=$request;
        $this->response=$response;
        $this->view=$view;
        $this->container=$container;
        $this->config=$config;
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
        return $this->request->param($param,Request::ALL_PARAM,$default);
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
        $this->response->header($name,$value);
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
        if(is_array($params)) {
            $this->response->cookies($params);
            return;
        }
        $this->response->cookie(
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
        $this->response->contentType($type);
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
        $this->response->status($code);
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
        $this->response->status($code);
        return $this->response->json($data);
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
        $this->response->status($code);
        return $this->response->html($html);
    }

    /**
     * 显示视图
     *
     * @access protected
     * @param string|array|null $template 视图名称或数据(如果传入数组则为数据)
     * @param array<string,mixed> $data 数据
     * @return string
     * @throws Exception|ReflectionException
     */
    final protected function view(null|string|array $template=null,array $data=array()): string {
        if(is_array($template)) {
            $data=$template;
            $template=null;
        }
        if($this->config===null)
            throw new Exception('无法推断视图路径: 未注入配置契约(请让容器构建控制器)');
        // 路由上下文(应用名 / 控制器名 / 方法名)取自容器内的分发信息
        $route_info=$this->container===null?array():$this->container->getData('route_info',array());
        $route_info=is_array($route_info)?$route_info:array();
        if($template===null)
            $template=$route_info['method']??null;
        $template=$this->config->get('app.path').'/'.($route_info['app']??'').'/view'.'/'.($route_info['controller']??'').'/'.$template.'.html';
        $this->view->init($template,$data);
        return $this->html($this->view->render(),$this->response->status());
    }

}