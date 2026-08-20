<?php

namespace Tests;

use PHPUnit\Framework\TestCase;

use base\Database\Exception\TransactionException;
use base\Database\Transaction\NestedTransactionExecutor;
use base\Database\Transaction\TransactionContext;
use base\Database\Transaction\TransactionState;
use Tests\Fixtures\FakePdo;

/**
 * 事务层测试
 *
 * - 验证事务状态机与嵌套事务(保存点模拟)
 */
class DbalTransactionTest extends TestCase {

    /**
     * 测试开始与提交事务
     * @return void
     */
    public function testBeginCommit(): void {
        $pdo=new FakePdo();
        $state=new TransactionState();
        $executor=new NestedTransactionExecutor($pdo,$state);
        $context=new TransactionContext($state);
        $this->assertFalse($context->isActive());
        $executor->begin();
        $this->assertTrue($context->isActive());
        $this->assertSame(1,$context->getLevel());
        $executor->commit();
        $this->assertFalse($context->isActive());
        $this->assertSame(0,$context->getLevel());
        $this->assertSame(['begin','commit'],$pdo->transactionCalls);
    }

    /**
     * 测试开始与回滚事务
     * @return void
     */
    public function testBeginRollback(): void {
        $pdo=new FakePdo();
        $state=new TransactionState();
        $executor=new NestedTransactionExecutor($pdo,$state);
        $executor->begin();
        $executor->rollback();
        $this->assertFalse((new TransactionContext($state))->isActive());
        $this->assertSame(['begin','rollback'],$pdo->transactionCalls);
    }

    /**
     * 测试嵌套事务使用保存点
     * @return void
     */
    public function testNestedBeginUsesSavepoint(): void {
        $pdo=new FakePdo();
        $state=new TransactionState();
        $executor=new NestedTransactionExecutor($pdo,$state);
        $context=new TransactionContext($state);
        $executor->begin();
        $executor->begin();
        $this->assertSame(2,$context->getLevel());
        $this->assertSame(['SAVEPOINT sp_1'],$pdo->execCalls);
        $executor->commit();
        $this->assertSame(1,$context->getLevel());
        $this->assertSame('RELEASE SAVEPOINT sp_1',$pdo->execCalls[1]);
        $this->assertTrue($context->isActive());
        $executor->commit();
        $this->assertFalse($context->isActive());
        $this->assertSame(['begin','commit'],$pdo->transactionCalls);
    }

    /**
     * 测试嵌套事务回滚到保存点
     * @return void
     */
    public function testNestedRollbackToSavepoint(): void {
        $pdo=new FakePdo();
        $state=new TransactionState();
        $executor=new NestedTransactionExecutor($pdo,$state);
        $context=new TransactionContext($state);
        $executor->begin();
        $executor->begin();
        $executor->rollback();
        $this->assertSame(1,$context->getLevel());
        $this->assertSame('ROLLBACK TO SAVEPOINT sp_1',$pdo->execCalls[1]);
        $this->assertTrue($context->isActive());
    }

    /**
     * 测试未开启事务提交的负例
     * @return void
     */
    public function testCommitWithoutTransactionThrows(): void {
        $this->expectException(TransactionException::class);
        $this->expectExceptionCode(100604);
        $executor=new NestedTransactionExecutor(new FakePdo(),new TransactionState());
        $executor->commit();
    }

    /**
     * 测试显式设置保存点
     * @return void
     */
    public function testSetSavePoint(): void {
        $pdo=new FakePdo();
        $executor=new NestedTransactionExecutor($pdo,new TransactionState());
        $executor->setSavePoint('my_sp');
        $this->assertSame('SAVEPOINT my_sp',$pdo->execCalls[0]);
    }

    /**
     * 测试非法保存点名负例
     * @return void
     */
    public function testInvalidSavepointNameThrows(): void {
        $this->expectException(TransactionException::class);
        $this->expectExceptionCode(100605);
        $executor=new NestedTransactionExecutor(new FakePdo(),new TransactionState());
        $executor->setSavePoint('bad name; DROP');
    }

    /**
     * 测试只读标记
     * @return void
     */
    public function testReadOnlyFlag(): void {
        $state=new TransactionState();
        $context=new TransactionContext($state);
        $this->assertFalse($context->isReadOnly());
        $state->changeToReadOnly();
        $this->assertTrue($context->isReadOnly());
    }

}
