<?php

namespace base\Database\Coordinator\Handler;

use base\Database\Result\ResultInterface;
use base\Database\Query\QueryContextInterface;
use base\Database\Middleware\QueryMiddlewareInterface;
use base\Database\Coordinator\QueryExecutionDispatcherInterface;

/**
 * 查询中间件处理器接口
 * 
 * - 负责执行查询中间件链
 * - 分担中心协调器构建中间件链并执行的职责
 */
interface QueryMiddlewareHandlerInterface {

    /**
     * 封装查询执行器到中间件链中
     * - 此方法必须遵循【不可变模式】, 实现类绝不允许修改当前实例($this)的内部状态
     * @param QueryExecutionDispatcherInterface $dispatcher 查询执行分发器对象
     * @param QueryMiddlewareInterface[] $middlewares 中间件数组
     * @return self
     */
    public function wrap(
        QueryExecutionDispatcherInterface $dispatcher,
        array $middlewares
    ): self;

    /**
     * 执行查询中间件链
     * @param QueryContextInterface $context 查询上下文对象
     */
    public function execute(
        QueryContextInterface $context,
    ): ResultInterface;

}