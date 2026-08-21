<?php

namespace base\Database\Connection;

/**
 * 数据库连接池接口
 * 
 * - 负责连接资源的获取与归还
 * - 归还时回滚未完成事务并丢弃脏会话(查询失败或修改会话状态的连接不复用)
 */
interface ConnectionPoolInterface {

    /**
     * 从连接池中获取一个连接
     * @return ConnectionSessionInterface 获取的数据库连接会话实例
     */
    public function acquire(): ConnectionSessionInterface;

    /**
     * 将连接归还到连接池
     *  - 回滚未完成事务, 脏会话不再复用
     * @param ConnectionSessionInterface $connection 数据库连接会话实例
     * @return void
     */
    public function release(ConnectionSessionInterface $connection): void;

    /**
     * 关闭并销毁连接池中的所有连接
     * @return void
     */
    public function close(): void;

}
