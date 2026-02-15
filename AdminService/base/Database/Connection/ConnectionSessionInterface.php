<?php

namespace base\Database\Connection;

use base\Database\Sql\Dialect\DialectInterface;
use base\Database\Execution\SqlExecutorInterface;
use base\Database\Execution\TransactionExecutorInterface;
use base\Database\Transaction\TransactionContextInterface;

/**
 * 连接会话接口
 * - 负责管理连接资源、事务和执行器
 * - 内部需持有事务上下文对象和事务状态对象
 * - 内部需要持有支持的方言对象 (连接与方言本身就是强关联的, 解耦或复用无意义)
 */
interface ConnectionSessionInterface {

    /**
     * 获取Sql执行器
     * @return SqlExecutorInterface
     */
    public function getSqlExecutor(): SqlExecutorInterface;

    /**
     * 获取事务上下文
     * @return TransactionContextInterface
     */
    public function getTransactionContext(): TransactionContextInterface;

    /**
     * 获取事务执行器
     * @return TransactionExecutorInterface
     */
    public function getTransactionExecutor(): TransactionExecutorInterface;

    /**
     * 获取支持的方言
     * @return DialectInterface
     */
    public function getDialect(): DialectInterface;

    /**
     * 释放连接资源
     * @return void
     */
    public function release(): void;

    /**
     * 获取是否已释放
     * @return bool
     */
    public function isReleased(): bool;

    /**
     * 重置连接会话
     * - 完全清理连接会话状态(包括事务状态,临时表等)
     * @return void
     */
    public function reset(): void;

}