<?php

namespace base\Database\Transaction;

use PDO;
use PDOException;
use base\Database\Exception\TransactionException;
use base\Database\Execution\NestedTransactionExecutorInterface;

use function preg_match;

/**
 * 嵌套事务执行器
 *
 * - 嵌套事务通过保存点模拟(PDO/MySQL 不原生支持嵌套事务)
 * - 内部持有裸连接对象与事务状态对象
 */
final class NestedTransactionExecutor implements NestedTransactionExecutorInterface {

    /**
     * 裸连接对象
     * @var PDO
     */
    private PDO $connection;

    /**
     * 事务状态对象
     * @var TransactionStateInterface
     */
    private TransactionStateInterface $state;

    /**
     * 构造方法
     *
     * @access public
     * @param PDO $connection 裸连接对象
     * @param TransactionStateInterface $state 事务状态对象
     */
    public function __construct(PDO $connection,TransactionStateInterface $state) {
        $this->connection=$connection;
        $this->state=$state;
    }

    /**
     * 开始事务(已在事务中则创建保存点)
     *
     * @access public
     * @return void
     * @throws TransactionException
     */
    public function begin() {
        if($this->state->isActive()) {
            // 嵌套: 创建保存点
            $name=$this->savepointName($this->state->getLevel());
            $this->exec('SAVEPOINT '.$name);
            $this->state->levelIncrease();
            return;
        }
        try {
            $this->connection->beginTransaction();
        } catch(PDOException $e) {
            throw new TransactionException('Transaction start failed.',100601,array(
                'error'=>$e->getMessage()
            ));
        }
        $this->state->levelIncrease();
    }

    /**
     * 提交事务(嵌套则释放保存点)
     *
     * @access public
     * @return void
     * @throws TransactionException
     */
    public function commit() {
        $this->checkActive();
        if($this->state->getLevel()>1) {
            // 嵌套提交: 释放保存点
            $name=$this->savepointName($this->state->getLevel()-1);
            $this->exec('RELEASE SAVEPOINT '.$name);
            $this->state->levelDecrease();
            return;
        }
        try {
            $this->connection->commit();
        } catch(PDOException $e) {
            throw new TransactionException('Transaction commit failed.',100602,array(
                'error'=>$e->getMessage()
            ));
        }
        $this->state->changeToInactive();
    }

    /**
     * 回滚事务(嵌套则回滚到保存点)
     *
     * @access public
     * @return void
     * @throws TransactionException
     */
    public function rollback() {
        $this->checkActive();
        if($this->state->getLevel()>1) {
            // 嵌套回滚: 回滚到保存点
            $name=$this->savepointName($this->state->getLevel()-1);
            $this->exec('ROLLBACK TO SAVEPOINT '.$name);
            $this->state->levelDecrease();
            return;
        }
        try {
            $this->connection->rollBack();
        } catch(PDOException $e) {
            throw new TransactionException('Transaction rollback failed.',100603,array(
                'error'=>$e->getMessage()
            ));
        }
        $this->state->changeToInactive();
    }

    /**
     * 设置保存点
     *
     * @access public
     * @param string $name 保存点名
     * @return void
     * @throws TransactionException
     */
    public function setSavePoint(string $name) {
        $this->exec('SAVEPOINT '.$this->sanitizeSavepoint($name));
    }

    /**
     * 回滚到保存点
     *
     * @access public
     * @param string $name 保存点名
     * @return void
     * @throws TransactionException
     */
    public function rollBackToSavePoint(string $name) {
        $this->exec('ROLLBACK TO SAVEPOINT '.$this->sanitizeSavepoint($name));
    }

    /**
     * 校验事务是否活跃
     *
     * @access private
     * @return void
     * @throws TransactionException
     */
    private function checkActive(): void {
        if(!$this->state->isActive())
            throw new TransactionException('Transaction has not been started.',100604);
    }

    /**
     * 生成内部保存点名
     *
     * @access private
     * @param int $level 事务级别
     * @return string
     */
    private function savepointName(int $level): string {
        return 'sp_'.$level;
    }

    /**
     * 校验用户提供的保存点名
     *
     * @access private
     * @param string $name 保存点名
     * @return string
     * @throws TransactionException
     */
    private function sanitizeSavepoint(string $name): string {
        if(!preg_match('/^[A-Za-z0-9_]+$/',$name))
            throw new TransactionException('Invalid savepoint name.',100605,array(
                'name'=>$name
            ));
        return $name;
    }

    /**
     * 执行无结果 SQL
     *
     * @access private
     * @param string $sql SQL
     * @return void
     * @throws TransactionException
     */
    private function exec(string $sql): void {
        try {
            $this->connection->exec($sql);
        } catch(PDOException $e) {
            throw new TransactionException('Transaction operation failed.',100606,array(
                'sql'=>$sql,
                'error'=>$e->getMessage()
            ));
        }
    }

}
