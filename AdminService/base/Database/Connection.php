<?php

namespace base\Database;

use \PDO;
use \Closure;
use \PDOException;
use AdminService\exception\Sql\ConnectionException;

/**
 * 连接实例
 * 
 *  - 连接被关闭后可以再次打开
 *  - 连接被关闭时, 如果存在未完成的事务(PDO未被销毁前)
 *  - 则根据 transactionCloseBehavior 属性决定行为
 */
class Connection {

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
     * 连接标识(仅用于标识连接池中的连接ID)
     * @var int
     */
    protected int $connectionId=0;

    /**
     * 事务状态标记
     * @var int
     */
    protected int $transactionStates=self::TX_IDLE;

    /**
     * PDO 连接对象
     * @var PDO
     */
    protected ?PDO $pdo=null;

    /**
     * 懒加载的 PDO 连接闭包
     * @var Closure
     */
    protected Closure $pdoLazy;

    /**
     * 关闭连接时发生的错误的回调
     * @var Closure|null
     */
    protected ?Closure $onCloseError=null;

    /**
     * 连接关闭时未完成事务的处理行为
     * @var int
     */
    protected int $transactionCloseBehavior=0;

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
    ) {
        $this->pdoLazy=$pdoLazy;
        $this->connectionId=$connectionId;
        $this->transactionCloseBehavior=$transactionCloseBehavior;
        $this->onCloseError=$onCloseError;
    }

    /**
     * 检查是否已经连接
     * @return bool
     */
    public function isConnected(): bool {
        return $this->pdo!==null;
    }

    /**
     * 获取当前事务状态
     * @return int
     */
    public function getTransactionStates(): int {
        return $this->transactionStates;
    }

    /**
     * 开启事务
     * @throws ConnectionException
     * @return void
     */
    public function beginTransaction(): void {
        if(
            $this->transactionStates===self::TX_NOT_ALLOWED
            || $this->transactionStates===self::TX_UNKNOW
        ) {
            throw new ConnectionException('Transaction is not allowed');
        }
        if(!$this->inTransaction()) {
            try {
                $this->getPdo()->beginTransaction();
                $this->transactionStates=self::TX_BUSY;
            } catch(PDOException $e) {
                $this->transactionStates=self::TX_IDLE;
                throw new ConnectionException(
                    'Begin transaction failed',
                    0,
                    [
                        'msg'=>$e->getMessage(),
                        'error'=>$e
                    ]
                );
            }
        }
    }

    /**
     * 提交事务
     * @throws ConnectionException
     * @return void
     */
    public function commit(): void {
        if($this->inTransaction()) {
            try {
                $this->getPdo()->commit();
                $this->transactionStates=self::TX_IDLE;
            } catch(PDOException $e) {
                $this->transactionStates=self::TX_UNKNOW;
                throw new ConnectionException(
                    'Commit transaction failed',
                    0,
                    [
                        'msg'=>$e->getMessage(),
                        'error'=>$e
                    ]
                );
            }
        }
    }

    /**
     * 回滚事务
     * @throws ConnectionException
     * @return void
     */
    public function rollBack(): void {
        if($this->inTransaction()) {
            try {
                $this->getPdo()->rollBack();
                $this->transactionStates=self::TX_IDLE;
            } catch(PDOException $e) {
                $this->transactionStates=self::TX_UNKNOW;
                throw new ConnectionException(
                    'Rollback transaction failed',
                    0,
                    [
                        'msg'=>$e->getMessage(),
                        'error'=>$e
                    ]
                );
            }
        }
    }

    /**
     * 设置当前事务状态
     * @param int $states 事务状态
     * @return void
     */
    public function setTransactionStates(int $states): void {
        $this->transactionStates=$states;
    }

    /**
     * 获取连接标识
     * @return int
     */
    public function getConnectionId(): int {
        return $this->connectionId;
    }

    /**
     * 判断连接是否在事务中
     * @return bool
     */
    public function inTransaction(): bool {
        try {
            return $this->isConnected() && $this->pdo->inTransaction();
        } catch(PDOException) {
            return false;
        }
    }

    /**
     * 设置事务关闭行为
     * @param int $behavior 事务关闭行为
     *  - 如果为 `self::TX_BEHAVIOR_ROLLBACK`, 则在请求结束时自动回滚事务
     *  - 如果为 `self::TX_BEHAVIOR_COMMIT`, 则在请求结束时自动提交事务
     * @return void
     */
    public function setTransactionCloseBehavior(int $behavior): void {
        $this->transactionCloseBehavior=$behavior;
    }

    /**
     * 获取事务关闭行为
     * @return int
     */
    public function getTransactionCloseBehavior(): int {
        return $this->transactionCloseBehavior;
    }

    /**
     * 设置关闭连接时发生的错误的回调
     * @param Closure|null $onCloseError 关闭连接时发生的错误的回调
     *  - 如果`$onCloseError`为`null`, 则关闭连接发生错误时不执行任何操作
     *  - 如果`$onCloseError`为`Closure`, 则在关闭连接发生错误时执行`$onCloseError`
     *  - 仅支持一个参数: 第一个参数为 `PDOException` 对象
     * @return void
     */
    public function setOnCloseError(?Closure $onCloseError): void {
        $this->onCloseError=$onCloseError;
    }

    /**
     * 获取 PDO 连接对象
     * @return PDO
     * @throws ConnectionException 数据库连接异常
     */
    public function getPdo(): PDO {
        if(!$this->isConnected()) {
            $closure=$this->pdoLazy;
            try {
                $pdo=$closure();
            } catch(PDOException $e) {
                throw new ConnectionException("数据库连接失败: " . $e->getMessage());
            }
            // 验证是否为 PDO 对象
            if(!$pdo instanceof PDO) {
                throw new ConnectionException("错误的 PDO 连接闭包");
            }
            $this->pdo=$pdo;
        }
        return $this->pdo;
    }

    /**
     * 关闭数据库连接
     * @return void
     */
    public function close(): void {
        if(!$this->isConnected()) {
            return;
        }
        try {
            // 判断是否存在事务
            if($this->pdo instanceof PDO && $this->pdo->inTransaction()) {
                // 如果存在事务, 则根据 transactionCloseBehavior 属性决定行为
                switch($this->transactionCloseBehavior) {
                    case self::TX_BEHAVIOR_COMMIT:
                        $this->pdo->commit();
                        break;
                    case self::TX_BEHAVIOR_ROLLBACK:
                        $this->pdo->rollback();
                        break;
                }
            }
        } catch(PDOException $e) {
            $this->onCloseError && ($this->onCloseError)($e);
        } finally {
            $this->pdo=null;
            // 将忙碌和未知状态置为空闲状态
            if(
                $this->transactionStates===self::TX_BUSY
                || $this->transactionStates===self::TX_UNKNOW
                ) {
                $this->transactionStates=self::TX_IDLE;
            }
        }
    }

}