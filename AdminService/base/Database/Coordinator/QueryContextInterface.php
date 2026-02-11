<?php

namespace base\Database\Coordinator;

use base\Database\Compiler\CompiledQueryInterface;

/**
 * 查询上下文接口
 */
interface QueryContextInterface {

    /**
     * 获取已编译的查询实例
     */
    public function getCompiledQuery(): CompiledQueryInterface;

    /**
     * 获取查询类型
     * @see \base\Database\Type\QueryType
     * @return int
     */
    public function getQueryType(): int;

}