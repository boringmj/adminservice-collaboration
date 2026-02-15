<?php

namespace base\Database\Sql\Builder;

use base\Database\Query\QueryInterface;
use base\Database\Sql\Definition\StatementDefinitionInterface;

/**
 * SQL语句构建器接口
 */
interface StatementBuilderInterface {

    /**
     * 构建SQL语句对象
     * @param QueryInterface $query 查询对象
     * @return StatementDefinitionInterface
     */
    public function build(
        QueryInterface $query
    ): StatementDefinitionInterface;

}