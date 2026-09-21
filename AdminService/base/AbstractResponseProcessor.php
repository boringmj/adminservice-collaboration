<?php

namespace base;

/**
 * 响应处理类
 *
 * - 处理须显式调用 `handle()`, 不在构造函数中产生副作用
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
     * @param array<string,mixed> $config 配置项
     */
    public function __construct(Response $response,array $config) {
        $this->response=$response;
        $this->config=$config;
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
     * @access public
     * @return void
     */
    abstract public function handle(): void;

}
