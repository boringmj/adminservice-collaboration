<?php

namespace base\Database\Sql\Builder;

use base\Database\Coordinator\QueryDefinitionInterface;
use base\Database\Sql\Compiler\CompilerContextInterface;
use base\Database\Sql\Compiler\CompiledStatementInterface;
use base\Database\Sql\Compiler\SqlCompilerInterface;

/**
 * SQL语句构建器接口
 */
interface StatementBuilderInterface {

    /**
     * 将查询定义编译为编译后的查询对象
     * @param SqlCompilerInterface $compiler SQL编译器
     * @param QueryDefinitionInterface $queryDefinition 查询定义
     * @param CompilerContextInterface $context 编译器上下文
     * @return CompiledStatementInterface
     */
    public function compile(
        SqlCompilerInterface $compiler,
        QueryDefinitionInterface $queryDefinition,
        CompilerContextInterface $context
    ): CompiledStatementInterface;

}