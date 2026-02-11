<?php

namespace base\Database\Compiler;

use base\Database\Builder\BuilderInterface;

/**
 * SQL编译器接口
 */
interface SqlCompilerInterface {

    /**
     * 编译查询
     * @param BuilderInterface $builder 查询构建器
     * @return CompiledQueryInterface
     */
    public function compile(BuilderInterface $builder): CompiledQueryInterface;

}