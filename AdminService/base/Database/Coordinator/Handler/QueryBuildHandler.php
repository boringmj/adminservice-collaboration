<?php

namespace base\Database\Coordinator\Handler;

use base\Database\Connection\ConnectionSessionInterface;
use base\Database\Exception\QueryException;
use base\Database\Query\QueryContextInterface;
use base\Database\Sql\Compiler\CompiledStatementInterface;
use base\Database\Sql\Compiler\SqlCompilerInterface;
use base\Database\Sql\Builder\StatementBuilderInterface;

/**
 * 查询构建处理器
 *
 * - 从查询上下文取查询对象, 经构建器与编译器产出已编译语句
 * - 编译上下文由连接会话提供(方言/表前缀/命名策略)
 */
final class QueryBuildHandler implements QueryBuildHandlerInterface {

    /**
     * 执行查询构建器
     *
     * @access public
     * @param QueryContextInterface $context 查询上下文对象
     * @param ConnectionSessionInterface $connection 连接会话对象
     * @param StatementBuilderInterface $builder SQL 语句构建器对象
     * @param SqlCompilerInterface $compiler SQL 编译器对象
     * @return CompiledStatementInterface
     * @throws QueryException 上下文不携带查询对象(原生 SQL 语句)
     */
    public function execute(
        QueryContextInterface $context,
        ConnectionSessionInterface $connection,
        StatementBuilderInterface $builder,
        SqlCompilerInterface $compiler
    ): CompiledStatementInterface {
        $query=$context->getQuery();
        if($query===null)
            throw new QueryException('Query context does not carry a query object.',100701);
        $definition=$builder->build($query);
        return $compiler->compile($definition,$connection->getCompilerContext());
    }

}
