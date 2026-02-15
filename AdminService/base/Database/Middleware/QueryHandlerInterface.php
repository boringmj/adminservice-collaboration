<?php

namespace base\Database\Middleware;

use base\Database\Result\ResultInterface;
use base\Database\Query\QueryContextInterface;

/**
 * 查询处理器接口
 * 
 * - 为中间件提供的类型安全的处理器
 */
interface QueryHandlerInterface {

    /**
     * 处理查询
     * @param QueryContextInterface $query 查询上下文
     * @return ResultInterface
     */
    public function handle(QueryContextInterface $query): ResultInterface;

}