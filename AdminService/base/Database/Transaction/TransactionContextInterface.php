<?php

namespace base\Database\Transaction;

/**
 * 事务上下文接口
 * 
 * - 仅承担对外暴露事务状态的职责
 * - 未来扩展严禁添加任何执行事务的逻辑
 * - 需要在内部持有一个事务状态对象
 */
interface TransactionContextInterface {

    /**
     * 是否处于事务活跃状态
     * @return bool 是否处于事务活跃状态
     */
    public function isActive(): bool;

    /**
     * 获取事务级别
     * @return int 事务级别
     */

    public function getLevel(): int;

    /**
     * 事务是否只读
     * @return bool 是否只读
     */
    public function isReadOnly(): bool;

}