<?php

namespace base;

/**
 * 响应处理类
 */
abstract class AbstractResponseProcessor {

    /**
     * Response对象
     * @var Response
     */
    protected Response $response;

    /**
     * 配置项
     * @var array
     */
    protected array $config=[];

    /**
     * 构造方法
     * 
     * @access public
     * @param Response $response Response对象
     * @param array $config 配置项
     * @return string
     */
    public function __construct(Response $response,array $config) {
        $this->response=$response;
        $this->config=$config;
        $this->handle();
    }

    /**
     * 获取Response对象
     * 
     * @access public
     * @return Response
     */
    public function getResponse(): Response {
        return $this->response;
    }

    /**
     * 处理响应数据
     * 
     * @access protected
     * @return void
     */
    abstract protected function handle(): void;

}