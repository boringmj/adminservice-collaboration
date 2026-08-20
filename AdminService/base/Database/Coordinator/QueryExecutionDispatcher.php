<?php

namespace base\Database\Coordinator;

use base\Database\Execution\SqlExecutorInterface;
use base\Database\Query\QueryContextInterface;
use base\Database\Result\ResultInterface;
use base\Database\Sql\Compiler\CompiledStatementInterface;

/**
 * 查询执行调度器
 *
 * - 将已编译语句交由执行器执行
 * - 不持有连接资源, 由调用方传入执行器
 */
final class QueryExecutionDispatcher implements QueryExecutionDispatcherInterface {

    /**
     * 执行查询
     *
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
    ): ResultInterface {
        return $executor->execute($statement);
    }

}
