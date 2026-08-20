<?php

namespace base\Database\Transaction;

/**
 * 事务上下文
 *
 * - 仅对外暴露事务状态, 不承担任何执行逻辑
 * - 内部持有一个事务状态对象
 */
final class TransactionContext implements TransactionContextInterface {

    /**
     * 事务状态对象
     * @var TransactionState
     */
    private TransactionState $state;

    /**
     * 构造方法
     *
     * @access public
     * @param TransactionState $state 事务状态对象
     */
    public function __construct(TransactionState $state) {
        $this->state=$state;
    }

    /**
     * 是否处于事务活跃状态
     *
     * @access public
     * @return bool
     */
    public function isActive(): bool {
        return $this->state->isActive();
    }

    /**
     * 获取事务级别
     *
     * @access public
     * @return int
     */
    public function getLevel(): int {
        return $this->state->getLevel();
    }

    /**
     * 事务是否只读
     *
     * @access public
     * @return bool
     */
    public function isReadOnly(): bool {
        return $this->state->isReadOnly();
    }

}
