<?php

namespace base\Database\Middleware;

use base\Database\Result\ResultInterface;
use base\Database\Query\QueryContextInterface;

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
    public function process(
        QueryContextInterface $query,
        QueryHandlerInterface $next
    ): ResultInterface;

}