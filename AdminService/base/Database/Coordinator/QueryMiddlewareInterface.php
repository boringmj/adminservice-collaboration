<?php

namespace base\Database\Coordinator;

use base\Database\Result\ResultInterface;

/**
 * 查询中间件接口
 * 
 * - 负责查询执行前后处理逻辑
 */
interface QueryMiddlewareInterface {

    /**
     * 处理查询
     * @param QueryContextInterface $query 查询上下文
     * @param QueryHandlerInterface $next 下一个查询处理器
     * @return ResultInterface
     */
    public function handle(
        QueryContextInterface $query,
        QueryHandlerInterface $next
    ): ResultInterface;

}