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

}