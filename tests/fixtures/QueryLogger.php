<?php

namespace Tests\Fixtures;

use base\Database\Middleware\QueryHandlerInterface;
use base\Database\Middleware\QueryMiddlewareInterface;
use base\Database\Query\QueryContextInterface;
use base\Database\Result\ResultInterface;

/**
 * 测试用查询中间件
 */
class QueryLogger implements QueryMiddlewareInterface {

    /**
     * 处理查询
     *
     * @access public
     * @param QueryContextInterface $query 查询上下文
     * @param QueryHandlerInterface $next 下一个处理器
     * @return ResultInterface
     */
    public function process(QueryContextInterface $query,QueryHandlerInterface $next): ResultInterface {
        return $next->handle($query);
    }

}
