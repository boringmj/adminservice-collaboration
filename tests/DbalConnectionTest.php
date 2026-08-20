<?php

namespace Tests;

use PHPUnit\Framework\TestCase;

use base\Database\Connection\PdoConnectionManager;
use base\Database\Connection\PdoConnectionPool;
use base\Database\Connection\PdoConnectionSession;
use base\Database\Execution\SqlExecutorInterface;
use base\Database\Execution\TransactionExecutorInterface;
use base\Database\Sql\Dialect\MysqlDialect;
use base\Database\Transaction\TransactionContextInterface;
use Tests\Fixtures\FakePdo;

/**
 * 连接层测试
 *
 * - 验证连接会话组件装配、连接池复用、会话清理与共享/独占连接
 */
class DbalConnectionTest extends TestCase {

    /**
     * 创建会话工厂
     *
     * @access private
     * @param FakePdo|null $pdo 假连接
     * @return callable
     */
    private function sessionFactory(?FakePdo $pdo=null): callable {
        $pdo=$pdo??new FakePdo();
        return function() use ($pdo) {
            return new PdoConnectionSession($pdo,new MysqlDialect());
        };
    }

    /**
     * 测试连接会话提供各组件
     * @return void
     */
    public function testSessionProvidesComponents(): void {
        $session=new PdoConnectionSession(new FakePdo(),new MysqlDialect());
        $this->assertInstanceOf(SqlExecutorInterface::class,$session->getSqlExecutor());
        $this->assertInstanceOf(TransactionContextInterface::class,$session->getTransactionContext());
        $this->assertInstanceOf(TransactionExecutorInterface::class,$session->getTransactionExecutor());
        $this->assertSame('mysql',$session->getDialect()->getName());
        $this->assertSame('mysql',$session->getCompilerContext()->getDialect()->getName());
        $this->assertFalse($session->isReleased());
    }

    /**
     * 测试连接池复用会话
     * @return void
     */
    public function testPoolReusesSession(): void {
        $pool=new PdoConnectionPool($this->sessionFactory());
        $first=$pool->acquire();
        $this->assertFalse($first->isReleased());
        $first->release();
        $this->assertTrue($first->isReleased());
        $second=$pool->acquire();
        $this->assertSame($first,$second);
        $this->assertFalse($second->isReleased());
    }

    /**
     * 测试会话重置回滚未完成事务
     * @return void
     */
    public function testSessionResetRollsBackTransaction(): void {
        $pdo=new FakePdo();
        $session=new PdoConnectionSession($pdo,new MysqlDialect());
        $session->getTransactionExecutor()->begin();
        $this->assertTrue($session->getTransactionContext()->isActive());
        $session->reset();
        $this->assertFalse($session->getTransactionContext()->isActive());
        $this->assertSame(['begin','rollback'],$pdo->transactionCalls);
    }

    /**
     * 测试共享与独占连接
     * @return void
     */
    public function testManagerSharedAndExclusive(): void {
        $factory=$this->sessionFactory();
        $manager=new PdoConnectionManager(new PdoConnectionPool($factory),$factory);
        $shared=$manager->getConnection();
        $exclusive=$manager->getExclusiveConnection();
        $this->assertNotSame($shared,$exclusive);
        // 共享连接释放后复用
        $shared->release();
        $reacquired=$manager->getConnection();
        $this->assertSame($shared,$reacquired);
    }

    /**
     * 测试脏会话被丢弃(不复用, 下次新建)
     * @return void
     */
    public function testPoolDiscardsDirtySession(): void {
        $pdo=new FakePdo();
        $factoryCalls=0;
        $factory=function() use ($pdo,&$factoryCalls) {
            $factoryCalls++;
            return new PdoConnectionSession($pdo,new MysqlDialect());
        };
        $pool=new PdoConnectionPool($factory);
        $first=$pool->acquire();
        $first->markDirty();
        $first->release();
        // 脏会话被丢弃, 再次获取新建连接
        $second=$pool->acquire();
        $this->assertNotSame($first,$second);
        $this->assertSame(2,$factoryCalls);
    }

    /**
     * 测试独占会话释放时重置(回滚残留事务)
     * @return void
     */
    public function testExclusiveReleaseResetsTransaction(): void {
        $pdo=new FakePdo();
        $session=new PdoConnectionSession($pdo,new MysqlDialect());
        $session->getTransactionExecutor()->begin();
        $this->assertTrue($session->getTransactionContext()->isActive());
        $session->release();
        // 非池化会话 release 同样 reset → 回滚
        $this->assertTrue($session->isReleased());
        $this->assertFalse($session->getTransactionContext()->isActive());
        $this->assertSame(array('begin','rollback'),$pdo->transactionCalls);
    }

    /**
     * 测试连接池闲置上限(超过丢弃)
     * @return void
     */
    public function testPoolBoundedMaxIdle(): void {
        $pool=new PdoConnectionPool($this->sessionFactory(),2);
        $a=$pool->acquire();
        $b=$pool->acquire();
        $c=$pool->acquire();
        $a->release();
        $b->release();
        $c->release();
        // 闲置上限 2: 只保留 a、b, c 被丢弃
        $x=$pool->acquire();
        $this->assertSame($b,$x); // LIFO 弹出最后闲置的
        $y=$pool->acquire();
        $this->assertSame($a,$y);
    }

}
