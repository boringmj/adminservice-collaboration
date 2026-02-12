<?php

namespace base\Database\Sql\Compiler;

use base\Database\Sql\Dialect\DialectInterface;

/**
 * 编译器上下文
 */
interface CompilerContextInterface {

    /**
     * 获取方言
     * @return DialectInterface
     */
    public function getDialect(): DialectInterface;

    /**
     * 获取编译模式
     * @return CompileModeInterface
     */
    public function getMode(): CompileModeInterface;

    /**
     * 获取表前缀
     * @return string
     */
    public function getTablePrefix(): string;

    /**
     * 获取命名策略
     * @return NamingStrategyInterface
     */
    public function getNamingStrategy(): NamingStrategyInterface;

    /**
     * 是否支持特性
     * @param int $flag 特性标志
     * @see \base\Database\Sql\Type\CompileFeature
     * @return bool
     */
    public function hasFeature(int $flag): bool;

}