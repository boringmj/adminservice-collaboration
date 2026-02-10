<?php

namespace base\Database\Connection;

use base\Database\Sql\SqlExecutorInterface;
use base\Database\Transaction\TransactionContextInterface;

/**
 * 连接会话接口
 * - 负责管理连接资源、事务和执行器
 */
interface ConnectionSessionInterface {

    /**
     * 获取执行器实例
     * @return SqlExecutorInterface
     */
    public function getExecutor(): SqlExecutorInterface;

    /**
     * 获取事务上下文
     * @return TransactionContextInterface
     */
    public function getTransactionContext(): TransactionContextInterface;

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