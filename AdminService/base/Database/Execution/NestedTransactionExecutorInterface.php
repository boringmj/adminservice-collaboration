<?php

namespace base\Database\Execution;
/**
 * 嵌套事务执行器接口
 * 
 * - 嵌套事务执行器用于执行嵌套事务, 包括开始事务、提交事务、回滚事务等
 * - 嵌套事务执行器应该在内部持有一个事务状态对象和裸连接对象
 * - 嵌套事务执行器支持设置保存点和回滚到保存点
 */
interface NestedTransactionExecutorInterface extends TransactionExecutorInterface {
    
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