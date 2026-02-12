<?php

namespace base\Database\Sql\Compiler;

use base\Database\Coordinator\QueryDefinitionInterface;

/**
 * SQL编译器接口
 */
interface SqlCompilerInterface {

    /**
     * 编译查询
     * @param QueryDefinitionInterface $builder 查询构建器
     * @param CompilerContextInterface $context 编译器上下文
     * @return CompiledStatementInterface
     */
    public function compile(
        QueryDefinitionInterface $builder,
        CompilerContextInterface $context
    ): CompiledStatementInterface;

}