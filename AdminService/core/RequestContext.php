<?php

namespace AdminService;

use base\AbstractSession;
use base\ConfigInterface;
use base\Container as ContainerContract;
use base\Request;
use base\Response;

/**
 * 请求级上下文
 *
 * - 持有一次请求内需要跨组件共享的请求级对象
 * - 由 Application::handle() 创建，供 Response / Error / 门面解析使用
 */
final class RequestContext {

    /**
     * 请求级容器
     * @var ContainerContract
     */
    private ContainerContract $container;

    /**
     * 请求对象
     * @var Request
     */
    private Request $request;

    /**
     * 响应对象
     * @var Response
     */
    private Response $response;

    /**
     * 会话对象
     * @var AbstractSession
     */
    private AbstractSession $session;

    /**
     * 请求级配置
     * @var ConfigInterface
     */
    private ConfigInterface $config;

    /**
     * 构造方法
     *
     * @access public
     * @param ContainerContract $container 请求级容器
     * @param Request $request 请求对象
     * @param Response $response 响应对象
     * @param AbstractSession $session 会话对象
     * @param ConfigInterface $config 请求级配置
     */
    public function __construct(
        ContainerContract $container,
        Request $request,
        Response $response,
        AbstractSession $session,
        ConfigInterface $config
    ) {
        $this->container=$container;
        $this->request=$request;
        $this->response=$response;
        $this->session=$session;
        $this->config=$config;
    }

    /**
     * 获取请求级容器
     *
     * @access public
     * @return ContainerContract
     */
    public function container(): ContainerContract {
        return $this->container;
    }

    /**
     * 获取请求对象
     *
     * @access public
     * @return Request
     */
    public function request(): Request {
        return $this->request;
    }

    /**
     * 获取响应对象
     *
     * @access public
     * @return Response
     */
    public function response(): Response {
        return $this->response;
    }

    /**
     * 获取会话对象
     *
     * @access public
     * @return AbstractSession
     */
    public function session(): AbstractSession {
        return $this->session;
    }

    /**
     * 获取请求级配置
     *
     * @access public
     * @return ConfigInterface
     */
    public function config(): ConfigInterface {
        return $this->config;
    }

}
