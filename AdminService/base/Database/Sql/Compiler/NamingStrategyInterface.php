<?php

namespace base\Database\Sql\Compiler;

/**
 * 命名策略接口
 * 
 * - 仅用于处理表名、列名、别名
 * - 未来扩展只能添加字符处理逻辑
 */
interface NamingStrategyInterface {

    /**
     * 处理表名
     */
    public function table(string $name): string;

    /**
     * 处理列名
     */
    public function column(string $name): string;

    /**
     * 处理别名
     */
    public function alias(string $name): string;

}