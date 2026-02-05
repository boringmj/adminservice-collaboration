<?php

namespace AdminService\Database;

use \PDO;
use \Closure;
use \PDOException;
use base\Database\AbstractConnection;
use AdminService\exception\Sql\ConnectionException;

/**
 * 连接实例
 * 
 *  - 连接被关闭后可以再次打开
 *  - 连接被关闭时, 如果存在未完成的事务(PDO未被销毁前)
 *  - 则根据 transactionCloseBehavior 属性决定行为
 */
class Connection extends AbstractConnection {

    /**
     * 事务状态标记
     * @var int
     */
    protected int $transactionStates=self::TX_IDLE;

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
            || $this->transactionStates===self::TX_UNKNOWN
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
                $this->transactionStates=self::TX_UNKNOWN;
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
                $this->transactionStates=self::TX_UNKNOWN;
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
                || $this->transactionStates===self::TX_UNKNOWN
                ) {
                $this->transactionStates=self::TX_IDLE;
            }
        }
    }

}