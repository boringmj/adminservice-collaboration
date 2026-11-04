<?php

namespace Tests\Fixtures;

use base\AbstractResponseProcessor;
use base\Response;

/**
 * 依赖请求级服务的响应处理器
 */
final class RequestScopedResponseProcessor extends AbstractResponseProcessor {

    /**
     * 请求级依赖
     * @var RequestScopedDependency
     */
    private RequestScopedDependency $dependency;

    /**
     * 构造方法
     *
     * @param Response $response 响应对象
     * @param array<string,mixed> $config 配置
     * @param RequestScopedDependency $dependency 请求级依赖
     */
    public function __construct(
        Response $response,
        array $config,
        RequestScopedDependency $dependency
    ) {
        parent::__construct($response,$config);
        $this->dependency=$dependency;
    }

    /**
     * 处理响应
     *
     * @return void
     */
    public function handle(): void {
        $this->getResponse()->rendered($this->dependency->value);
    }

}
