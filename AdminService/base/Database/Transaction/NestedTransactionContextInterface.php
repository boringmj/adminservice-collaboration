<?php

namespace base\Database\Transaction;

/**
 * 嵌套事务上下文接口
 */
interface NestedTransactionContextInterface extends TransactionContextInterface {

    /**
     * 获取事务级别
     * @return int
     */
    public function getLevel(): int;
    
    /**
     * 设置保存点
     * @param string $name
     * @return void
     */
    public function setSavePoint(string $name);

    /**
     * 回滚到保存点
     * @param string $name
     * @return void
     */
    public function rollBackToSavePoint(string $name);

}