<?php

namespace base\Database\Query;

use base\Database\Exception\QueryException;
use base\Database\Type\QueryType;
use base\Database\Type\StatementType;

use function in_array;

/**
 * 查询上下文
 *
 * - 实现 QueryContextInterface, 在协调器与中间件链中流转
 * - 查询语句携带查询对象; 原生 SQL 语句不携带查询对象, 记录原生 SQL
 */
final class QueryContext implements QueryContextInterface {

    /**
     * 查询对象(原生 SQL 语句为 null)
     * @var Query|null
     */
    private ?Query $query;

    /**
     * 原生 SQL(查询语句为 null)
     * @var string|null
     */
    private ?string $rawSql;

    /**
     * 查询类型(读/写)
     * @see \base\Database\Type\QueryType
     * @var int
     */
    private int $queryType;

    /**
     * 构造方法
     *
     * - 查询对象与原生 SQL 二选一: 原生 SQL 语句必须显式给出查询类型(无语句类型可推断)
     *
     * @access public
     * @param Query|null $query 查询对象
     * @param int|null $queryType 查询类型(查询对象缺省按语句类型推断)
     * @param string|null $rawSql 原生 SQL
     */
    public function __construct(?Query $query,?int $queryType=null,?string $rawSql=null) {
        if($query===null) {
            if($rawSql===null)
                throw new QueryException('Query context requires a query object or raw SQL.',100701);
            if($queryType===null)
                throw new QueryException('Raw SQL query context requires an explicit query type.',100701,array(
                    'sql'=>$rawSql
                ));
        }
        $this->query=$query;
        $this->rawSql=$query===null?$rawSql:null;
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
     * @return Query|null 查询语句为查询对象, 原生 SQL 语句为 null
     */
    public function getQuery(): ?Query {
        return $this->query;
    }

    /**
     * 获取原生 SQL
     *
     * @access public
     * @return string|null 原生 SQL 语句为 SQL 文本, 查询语句为 null
     */
    public function getRawSql(): ?string {
        return $this->rawSql;
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
