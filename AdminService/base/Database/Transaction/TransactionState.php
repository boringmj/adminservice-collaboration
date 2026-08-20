<?php

namespace base\Database\Transaction;

/**
 * 事务状态
 *
 * - 活跃状态由事务级别推导(level>0 视为活跃), 与接口方法一致
 * - changeToInactive 重置级别, 使状态回到非活跃
 */
final class TransactionState implements TransactionStateInterface {

    /**
     * 事务级别
     * @var int
     */
    private int $level=0;

    /**
     * 是否只读
     * @var bool
     */
    private bool $readOnly=false;

    /**
     * 是否处于事务活跃状态
     *
     * @access public
     * @return bool
     */
    public function isActive(): bool {
        return $this->level>0;
    }

    /**
     * 获取事务级别
     *
     * @access public
     * @return int
     */
    public function getLevel(): int {
        return $this->level;
    }

    /**
     * 事务级别递增
     *
     * @access public
     * @return void
     */
    public function levelIncrease(): void {
        $this->level++;
    }

    /**
     * 事务级别递减
     *
     * @access public
     * @return void
     */
    public function levelDecrease(): void {
        $this->level--;
    }

    /**
     * 改变事务状态为不再活跃
     *
     * @access public
     * @return void
     */
    public function changeToInactive(): void {
        $this->level=0;
    }

    /**
     * 改变事务状态为只读
     *
     * @access public
     * @return void
     */
    public function changeToReadOnly(): void {
        $this->readOnly=true;
    }

    /**
     * 是否只读(供事务上下文读取)
     *
     * @access public
     * @return bool
     */
    public function isReadOnly(): bool {
        return $this->readOnly;
    }

}
