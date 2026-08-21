<?php

namespace AdminService;

use base\Database\Query\QueryBuilder;
use base\Database\Result\ResultInterface;
use base\Database\Sql\Definition\Table;
use base\Database\Db as BaseDb;

/**
 * 数据库门面(框架层)
 *
 * - 裸查询的用户入口: use AdminService\Db;  Db::table('users')->where('id',1)->get()
 * - 委托给 base\Database 的 DBAL(默认连接), 框架层不做底层实现
 * - 需要指定命名连接时请直接用 base\Database\Db::fromConfig('连接名')
 */
final class Db {

    /**
     * 流式查询入口(默认连接)
     *
     * @access public
     * @param string|Table $table 表名
     * @param string|null $alias 表别名
     * @return QueryBuilder
     */
    public static function table(string|Table $table,?string $alias=null): QueryBuilder {
        return new QueryBuilder(BaseDb::fromConfig(),$table,$alias);
    }

    /**
     * 执行原生 SQL(默认连接)
     *
     * @access public
     * @param string $sql SQL
     * @param array $params 绑定参数
     * @return ResultInterface
     */
    public static function raw(string $sql,array $params=array()): ResultInterface {
        return BaseDb::fromConfig()->raw($sql,$params);
    }

    /**
     * 事务作用域(默认连接)
     *
     * @access public
     * @param callable(BaseDb $db): mixed $callback 回调(接收当前连接的底层 Db)
     * @return mixed
     */
    public static function transaction(callable $callback): mixed {
        return BaseDb::fromConfig()->transaction($callback);
    }

}
