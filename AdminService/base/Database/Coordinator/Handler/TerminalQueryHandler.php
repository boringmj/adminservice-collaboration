<?php

namespace base\Database\Coordinator\Handler;

use base\Database\Coordinator\QueryExecutionDispatcherInterface;
use base\Database\Execution\SqlExecutorInterface;
use base\Database\Middleware\QueryHandlerInterface;
use base\Database\Query\QueryContextInterface;
use base\Database\Result\ResultInterface;
use base\Database\Sql\Compiler\CompiledStatementInterface;

/**
 * 终端查询处理器
 *
 * - 中间件链的终点, 将已编译语句与执行器交给调度器执行
 */
final class TerminalQueryHandler implements QueryHandlerInterface {

    /**
     * 查询执行调度器
     * @var QueryExecutionDispatcherInterface
     */
    private QueryExecutionDispatcherInterface $dispatcher;

    /**
     * SQL 执行器
     * @var SqlExecutorInterface
     */
    private SqlExecutorInterface $executor;

    /**
     * 已编译语句
     * @var CompiledStatementInterface
     */
    private CompiledStatementInterface $statement;

    /**
     * 构造方法
     *
     * @access public
     * @param QueryExecutionDispatcherInterface $dispatcher 查询执行调度器
     * @param SqlExecutorInterface $executor SQL 执行器
     * @param CompiledStatementInterface $statement 已编译语句
     */
    public function __construct(
        QueryExecutionDispatcherInterface $dispatcher,
        SqlExecutorInterface $executor,
        CompiledStatementInterface $statement
    ) {
        $this->dispatcher=$dispatcher;
        $this->executor=$executor;
        $this->statement=$statement;
    }

    /**
     * 处理查询
     *
     * @access public
     * @param QueryContextInterface $query 查询上下文
     * @return ResultInterface
     */
    public function handle(QueryContextInterface $query): ResultInterface {
        return $this->dispatcher->execute($this->executor,$this->statement,$query);
    }

}
