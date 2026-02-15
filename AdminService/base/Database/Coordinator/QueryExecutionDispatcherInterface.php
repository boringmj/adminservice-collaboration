<?php

namespace base\Database\Coordinator;

use base\Database\Result\ResultInterface;
use base\Database\Query\QueryContextInterface;
use base\Database\Execution\SqlExecutorInterface;
use base\Database\Sql\Compiler\CompiledStatementInterface;


/**
 * 查询执行调度器接口
 * 
 * - 负责执行查询
 * - 分担中心协调器调度执行器的职责
 */
interface QueryExecutionDispatcherInterface {

    /**
     * 执行查询
     * @access public
     * @param SqlExecutorInterface $executor SQL 执行器对象
     * @param CompiledStatementInterface $statement 编译后的 SQL 语句对象
     * @param QueryContextInterface $context 查询上下文对象
     * @return ResultInterface
     */
    public function execute(
        SqlExecutorInterface $executor,
        CompiledStatementInterface $statement,
        QueryContextInterface $context
    ): ResultInterface;
}