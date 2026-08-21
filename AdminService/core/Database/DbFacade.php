<?php

namespace AdminService\Database;

use base\Database\Query\QueryBuilder;
use base\Database\Result\ResultInterface;
use base\Database\Sql\Definition\Table;
use base\Database\Db as BaseDb;

/**
 * 数据库门面基类(连接作用域)
 */
abstract class DbFacade {

    /**
     * 连接名
     * @var string
     */
    protected string $connection;

    /**
     * 构造方法
     *
     * @access public
     * @param string $connection 连接名(默认 default)
     */
    public function __construct(string $connection='default') {
        $this->connection=$connection;
    }

    /**
     * 流式查询入口(绑定本连接)
     *
     * @access public
     * @param string|Table $table 表名
     * @param string|null $alias 表别名
     * @return QueryBuilder
     */
    public function table(string|Table $table,?string $alias=null): QueryBuilder {
        return new QueryBuilder(BaseDb::fromConfig($this->connection),$table,$alias);
    }

    /**
     * 执行原生 SQL(绑定本连接)
     *
     * @access public
     * @param string $sql SQL
     * @param array $params 绑定参数
     * @return ResultInterface
     */
    public function raw(string $sql,array $params=array()): ResultInterface {
        return BaseDb::fromConfig($this->connection)->raw($sql,$params);
    }

    /**
     * 事务作用域(绑定本连接)
     *
     * @access public
     * @param callable(BaseDb $db): mixed $callback 回调(接收当前连接的底层 Db)
     * @return mixed
     */
    public function transaction(callable $callback): mixed {
        return BaseDb::fromConfig($this->connection)->transaction($callback);
    }

}
