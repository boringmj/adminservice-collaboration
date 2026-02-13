<?php

namespace base\Database\Coordinator\Handler;

use base\Database\Result\ResultInterface;
use base\Database\Coordinator\QueryContextInterface;
use base\Database\Middleware\QueryMiddlewareInterface;

/**
 * 查询中间件处理器接口
 * 
 * - 负责执行查询中间件链
 * - 分担中心协调器构建中间件链并执行的职责
 * - 负责包装最终执行器为中间件链的最后一个执行器
 */
interface QueryMiddlewareHandlerInterface {

    /**
     * 执行查询中间件链
     * @param QueryContextInterface $context 查询上下文对象
     * @param QueryExecutorHandlerInterface $finalExecutor 最终执行器对象
     * @param QueryMiddlewareInterface[] $middlewares 中间件数组
     * @return ResultInterface
     */
    public function execute(
        QueryContextInterface $context,
        QueryExecutorHandlerInterface $finalExecutor,
        array $middlewares
    ): ResultInterface;

}