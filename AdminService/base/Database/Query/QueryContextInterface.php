<?php

namespace base\Database\Query;

/**
 * 查询上下文接口
 */
interface QueryContextInterface {

    /**
     * 获取查询类型
     * @see \base\Database\Type\QueryType
     * @return int
     */
    public function getQueryType(): int;

    /**
     * 获取查询对象
     * @return QueryInterface|null 查询语句为查询对象, 原生 SQL 语句为 null
     */
    public function getQuery(): ?QueryInterface;

    /**
     * 获取原生 SQL
     * @return string|null 原生 SQL 语句为 SQL 文本, 查询语句为 null
     */
    public function getRawSql(): ?string;

}
