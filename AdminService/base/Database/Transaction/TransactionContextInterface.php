<?php

namespace base\Database\Transaction;

/**
 * 事务上下文接口
 */
interface TransactionContextInterface {

    /**
     * 是否在事务中
     * @return bool
     */
    public function isInTransaction(): bool;

    /**
     * 开始事务
     * @return void
     */
    public function begin();

    /**
     * 提交事务
     * @return void
     */
    public function commit();

    /**
     * 回滚事务
     * @return void
     */
    public function rollBack();

}