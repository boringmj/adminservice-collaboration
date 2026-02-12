<?php

namespace base\Database\Coordinator;

use base\Database\Sql\Compiler\CompiledStatementInterface;

/**
 * 查询上下文接口
 */
interface QueryContextInterface {

    /**
     * 获取已编译的 SQL 语句对象
     * @return CompiledStatementInterface
     */
    public function getCompiledStatement(): CompiledStatementInterface;

    /**
     * 获取查询类型
     * @see \base\Database\Type\QueryType
     * @return int
     */
    public function getQueryType(): int;

}