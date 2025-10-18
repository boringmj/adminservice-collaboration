<?php

namespace base\Database;

use \PDO;
use \Closure;

/**
 * 连接实例接口
 */
interface ConnectionInterface {

    /**
     * 连接关闭时提交未完成事务
     * @var int
     */
    public const TX_BEHAVIOR_COMMIT=1;

    /**
     * 连接关闭时回滚未完成事务
     * @var int
     */
    public const TX_BEHAVIOR_ROLLBACK=2;

    /**
     * 事务处于空闲状态
     * @var int
     */
    public const TX_IDLE=1;

    /**
     * 事务处于忙碌状态
     * @var int
     */
    public const TX_BUSY=2;
    
    /**
     * 不可开启事务(非强制, 强行开启事务可能会污染事务状态)
     * @var int
     */
    public const TX_NOT_ALLOWED=3;

    /**
     * 事务状态未知
     * @var int
     */
    public const TX_UNKNOW=4;

    /**
     * 构造函数
     * @param Closure $pdoLazy 懒加载的 PDO 连接闭包
     * @param int $connectionId 连接标识(仅用于标识连接池中的连接ID)
     * @param int $transactionCloseBehavior 连接关闭时未完成事务的处理行为
     *  - 如果为 `self::TX_BEHAVIOR_ROLLBACK`, 则在请求结束时自动回滚事务
     *  - 如果为 `self::TX_BEHAVIOR_COMMIT`, 则在请求结束时自动提交事务
     * @param Closure|null $onCloseError 关闭连接时发生的错误的回调
     *  - 仅支持一个参数: 第一个参数为 `PDOException` 对象
     * @return void
     */
    public function __construct(
        Closure $pdoLazy,
        int $connectionId,
        int $transactionCloseBehavior=self::TX_BEHAVIOR_ROLLBACK,
        ?Closure $onCloseError=null,
    );

    /**
     * 检查是否已经连接
     * @return bool
     */
    public function isConnected(): bool;

    /**
     * 获取当前事务状态
     * @return int
     */
    public function getTransactionStates(): int;

    /**
     * 开启事务
     * @return void
     */
    public function beginTransaction(): void;

    /**
     * 提交事务
     * @return void
     */
    public function commit(): void;

    /**
     * 回滚事务
     * @return void
     */
    public function rollBack(): void;

    /**
     * 设置当前事务状态
     * @param int $states 事务状态
     * @return void
     */
    public function setTransactionStates(int $states): void;

    /**
     * 获取连接标识
     * @return int
     */
    public function getConnectionId(): int;

    /**
     * 判断连接是否在事务中
     * @return bool
     */
    public function inTransaction(): bool;

    /**
     * 设置事务关闭行为
     * @param int $behavior 事务关闭行为
     *  - 如果为 `self::TX_BEHAVIOR_ROLLBACK`, 则在请求结束时自动回滚事务
     *  - 如果为 `self::TX_BEHAVIOR_COMMIT`, 则在请求结束时自动提交事务
     * @return void
     */
    public function setTransactionCloseBehavior(int $behavior): void;
    /**
     * 获取事务关闭行为
     * @return int
     */
    public function getTransactionCloseBehavior(): int;

    /**
     * 设置关闭连接时发生的错误的回调
     * @param Closure|null $onCloseError 关闭连接时发生的错误的回调
     *  - 如果`$onCloseError`为`null`, 则关闭连接发生错误时不执行任何操作
     *  - 如果`$onCloseError`为`Closure`, 则在关闭连接发生错误时执行`$onCloseError`
     *  - 仅支持一个参数: 第一个参数为 `PDOException` 对象
     * @return void
     */
    public function setOnCloseError(?Closure $onCloseError): void;

    /**
     * 获取 PDO 连接对象
     * @return PDO
     */
    public function getPdo(): PDO;

    /**
     * 关闭数据库连接
     * @return void
     */
    public function close(): void;

}