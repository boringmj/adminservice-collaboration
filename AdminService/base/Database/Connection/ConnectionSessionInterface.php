<?php

namespace base\Database\Connection;

use base\Database\Sql\Dialect\DialectInterface;
use base\Database\Sql\Compiler\CompilerContextInterface;
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
     * 获取编译器上下文
     *
     * - 会话持有方言、表前缀与命名策略, 可直接提供编译上下文
     * @return CompilerContextInterface
     */
    public function getCompilerContext(): CompilerContextInterface;

    /**
     * 释放连接资源
     * @return void
     */
    public function release(): void;

    /**
     * 设置所属连接池(池借出时调用, 标记会话归属)
     * @param ConnectionPoolInterface|null $pool 连接池
     * @return void
     */
    public function setPool(?ConnectionPoolInterface $pool): void;

    /**
     * 获取所属连接池(未归属则为 null)
     * @return ConnectionPoolInterface|null
     */
    public function getPool(): ?ConnectionPoolInterface;

    /**
     * 标记会话已取出使用(池借出时调用, 重置释放/污染标记)
     * @return void
     */
    public function checkout(): void;

    /**
     * 标记会话已释放(池归还时调用, 与 release() 守卫配合防重复归还)
     * @return void
     */
    public function markReleased(): void;

    /**
     * 获取是否已释放
     * @return bool
     */
    public function isReleased(): bool;

    /**
     * 重置连接会话
     * - 回滚未完成的事务, 清理事务状态
     * - 注: 会话变量/临时表等 PDO 无法重置, 需使用者自行规避(见 Demo-Sql 连接管理约定)
     * @return void
     */
    public function reset(): void;

    /**
     * 标记会话已污染(查询失败或执行了修改会话状态的 SQL)
     *
     * - 标记后归还连接池时将被丢弃, 不复用, 避免污染其他使用者
     *
     * @return void
     */
    public function markDirty(): void;

    /**
     * 判断会话是否已被标记为污染
     *
     * @return bool
     */
    public function isDirty(): bool;

}