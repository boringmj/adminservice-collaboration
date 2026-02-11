<?php

namespace base\Database\Execution;

/**
 * 事务执行器接口
 * 
 * - 事务执行器用于执行事务, 包括开始事务、提交事务、回滚事务等
 * - 事务执行器应该在内部持有一个事务状态对象和裸连接对象
 */
interface TransactionExecutorInterface {
    
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
    public function rollback();

}