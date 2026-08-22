<?php

namespace base\Database\Coordinator\Handler;

use base\Database\Result\ResultInterface;
use base\Database\Query\QueryContextInterface;
use base\Database\Execution\SqlExecutorInterface;
use base\Database\Sql\Compiler\CompiledStatementInterface;

/**
 * 查询中间件处理器接口
 *
 * - 负责执行查询中间件链
 * - 分担中心协调器构建中间件链并执行的职责
 */
interface QueryMiddlewareHandlerInterface {

    /**
     * 注入执行器与已编译语句(执行前由协调器调用)
     *
     * @access public
     * @param SqlExecutorInterface $executor SQL 执行器
     * @param CompiledStatementInterface $statement 已编译语句
     * @return static
     */
    public function configure(
        SqlExecutorInterface $executor,
        CompiledStatementInterface $statement
    ): static;

    /**
     * 执行查询中间件链
     *
     * @access public
     * @param QueryContextInterface $context 查询上下文对象
     * @return ResultInterface
     */
    public function execute(
        QueryContextInterface $context,
    ): ResultInterface;

}