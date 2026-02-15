<?php

namespace base\Database\Sql\Compiler;

use base\Database\Sql\Definition\StatementDefinitionInterface;

/**
 * SQL编译器接口
 */
interface SqlCompilerInterface {

    /**
     * 编译查询
     * @param StatementDefinitionInterface $definition 语句定义
     * @param CompilerContextInterface $context 编译器上下文
     * @return CompiledStatementInterface
     */
    public function compile(
        StatementDefinitionInterface $definition,
        CompilerContextInterface $context
    ): CompiledStatementInterface;

}