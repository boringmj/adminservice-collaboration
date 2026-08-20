<?php

namespace base\Database\Query;

use base\Database\Type\QueryType;
use base\Database\Type\StatementType;

use function in_array;

/**
 * 查询上下文
 *
 * - 实现 QueryContextInterface, 在协调器与中间件链中流转
 * - 内部持有查询对象与查询类型(读/写)
 */
final class QueryContext implements QueryContextInterface {

    /**
     * 查询对象
     * @var Query
     */
    private Query $query;

    /**
     * 查询类型(读/写)
     * @see \base\Database\Type\QueryType
     * @var int
     */
    private int $queryType;

    /**
     * 构造方法
     *
     * @access public
     * @param Query $query 查询对象
     * @param int|null $queryType 查询类型(默认根据语句类型推断)
     */
    public function __construct(Query $query,?int $queryType=null) {
        $this->query=$query;
        $this->queryType=$queryType??self::inferQueryType($query->getType());
    }

    /**
     * 获取查询类型
     *
     * @access public
     * @return int
     */
    public function getQueryType(): int {
        return $this->queryType;
    }

    /**
     * 获取查询对象
     *
     * @access public
     * @return Query
     */
    public function getQuery(): Query {
        return $this->query;
    }

    /**
     * 根据语句类型推断查询类型
     *
     * @access private
     * @param int $statementType 语句类型
     * @return int
     */
    private static function inferQueryType(int $statementType): int {
        if(in_array($statementType,array(
            StatementType::INSERT,
            StatementType::UPDATE,
            StatementType::DELETE
        ),true))
            return QueryType::WRITE;
        return QueryType::READ;
    }

}
