<?php

namespace base\Database;

use \Closure;

/**
 * 数据库连接工厂接口
 */
interface ConnectionFactoryInterface {

    /**
     * 创建连接
     * @param Closure $pdoLazy 懒加载的 PDO 连接闭包
     * @param int $connectionId 连接标识(仅用于标识连接池中的连接ID)
     * @param int $transactionCloseBehavior 连接关闭时未完成事务的处理行为
     *  - 如果为 `AbstractConnection::TX_BEHAVIOR_ROLLBACK`, 则在请求结束时自动回滚事务
     *  - 如果为 `AbstractConnection::TX_BEHAVIOR_COMMIT`, 则在请求结束时自动提交事务
     * @param Closure|null $onCloseError 关闭连接时发生的错误的回调
     *  - 仅支持一个参数: 第一个参数为 `PDOException` 对象
     * @return AbstractConnection 数据库连接实例
     */
    public function create(
        Closure $pdoLazy,
        int $connectionId=0,
        int $transactionCloseBehavior=AbstractConnection::TX_BEHAVIOR_ROLLBACK,
        ?Closure $onCloseError=null
    ): AbstractConnection;

}