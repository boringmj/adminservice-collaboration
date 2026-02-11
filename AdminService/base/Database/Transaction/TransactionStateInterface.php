<?php
namespace base\Database\Transaction;

/**
 * 事务状态接口
 * 
 * - 事务状态接口用于获取事务状态, 包括事务活跃状态、事务级别等
 * - 不可暴露给除事务上下文和事务执行器以外的任何组件
 */
interface TransactionStateInterface {

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
     * 事务级别递增
     * 
     * - 为执行器暴露的方法, 请勿在任何其他地方调用
     */

    public function levelIncrease(): void;

    /**
     * 事务级别递减
     * 
     * - 为执行器暴露的方法, 请勿在任何其他地方调用
     */
    public function levelDecrease(): void;

    /**
     * 改变事务状态为不再活跃
     * 
     * - 为执行器暴露的方法, 请勿在任何其他地方调用
     */
    public function changeToInactive(): void;

    /**
     * 改变事务状态为只读
     * 
     * - 为执行器暴露的方法, 请勿在任何其他地方调用
     */
    public function changeToReadOnly(): void;

}