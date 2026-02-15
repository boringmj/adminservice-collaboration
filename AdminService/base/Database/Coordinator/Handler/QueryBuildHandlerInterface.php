<?php

namespace base\Database\Coordinator\Handler;

use base\Database\Query\QueryContextInterface;
use base\Database\Sql\Compiler\SqlCompilerInterface;
use base\Database\Connection\ConnectionSessionInterface;
use base\Database\Sql\Builder\StatementBuilderInterface;
use base\Database\Sql\Compiler\CompiledStatementInterface;

/**
 * 查询构建处理器接口
 * 
 * - 负责构建SQL语句对象
 * - 分担中心协调器的查询构建工作
 */
interface QueryBuildHandlerInterface {

    /**
     * 执行查询构建器
     * @param QueryContextInterface $context 查询上下文对象
     * @param ConnectionSessionInterface $connection 连接会话对象
     * @param StatementBuilderInterface $builder SQL 语句构建器对象
     * @param SqlCompilerInterface $compiler SQL 编译器对象
     * @return CompiledStatementInterface
     */
    public function execute(
        QueryContextInterface $context,
        ConnectionSessionInterface $connection,
        StatementBuilderInterface $builder,
        SqlCompilerInterface $compiler
    ): CompiledStatementInterface;

}