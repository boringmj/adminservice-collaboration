<?php

namespace base\Database\Coordinator\Handler;

use base\Database\Middleware\QueryHandlerInterface;
use base\Database\Middleware\QueryMiddlewareInterface;
use base\Database\Query\QueryContextInterface;
use base\Database\Result\ResultInterface;

/**
 * 中间件链节点
 *
 * - 将单个中间件与下一个处理器组合为一个处理器
 */
final class QueryMiddlewareLink implements QueryHandlerInterface {

    /**
     * 中间件
     * @var QueryMiddlewareInterface
     */
    private QueryMiddlewareInterface $middleware;

    /**
     * 下一个处理器
     * @var QueryHandlerInterface
     */
    private QueryHandlerInterface $next;

    /**
     * 构造方法
     *
     * @access public
     * @param QueryMiddlewareInterface $middleware 中间件
     * @param QueryHandlerInterface $next 下一个处理器
     */
    public function __construct(QueryMiddlewareInterface $middleware,QueryHandlerInterface $next) {
        $this->middleware=$middleware;
        $this->next=$next;
    }

    /**
     * 处理查询
     *
     * @access public
     * @param QueryContextInterface $query 查询上下文
     * @return ResultInterface
     */
    public function handle(QueryContextInterface $query): ResultInterface {
        return $this->middleware->process($query,$this->next);
    }

}
