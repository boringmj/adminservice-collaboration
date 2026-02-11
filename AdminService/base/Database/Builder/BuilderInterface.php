<?php

namespace base\Database\Builder;

use base\Database\Compiler\SqlCompilerInterface;
use base\Database\Compiler\CompiledQueryInterface;

/**
 * 查询构建器接口
 */
interface BuilderInterface {

    /**
     * 编译为编译后的查询对象
     * @param SqlCompilerInterface $compiler SQL编译器
     * @return CompiledQueryInterface
     */
    public function compile(SqlCompilerInterface $compiler): CompiledQueryInterface;

}