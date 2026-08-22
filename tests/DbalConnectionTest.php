<?php

namespace Tests;

use PHPUnit\Framework\TestCase;

use base\Database\Connection\ConnectionConfig;
use base\Database\Connection\ConnectionPoolFactoryInterface;
use base\Database\Connection\ConnectionPoolInterface;
use base\Database\Connection\PdoConnectionManager;
use base\Database\Connection\PdoConnectionPool;
use base\Database\Connection\PdoConnectionPoolFactory;
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
     * 测试直接走池契约归还(绕过会话)是幂等的, 不会重复入池
     * @return void
     */
    public function testPoolReleaseDirectIdempotent(): void {
        $factoryCalls=0;
        $pdo=new FakePdo();
        $factory=function() use ($pdo,&$factoryCalls) {
            $factoryCalls++;
            return new PdoConnectionSession($pdo,new MysqlDialect());
        };
        $pool=new PdoConnectionPool($factory);
        $session=$pool->acquire();
        // 直接调池契约归还(绕过会话 release)
        $pool->release($session);
        // 会话自身再释放: 幂等守卫应拦截, 不重复入池
        $session->release();
        $a=$pool->acquire();
        $b=$pool->acquire();
        $this->assertSame($session,$a);           // 第一次复用同一会话
        $this->assertNotSame($session,$b);        // 若重复入池, 第二次也会是 $session
        $this->assertSame(2,$factoryCalls);       // 仅新建一条
    }

    /**
     * 测试跨池归还被归属校验拒绝
     * @return void
     */
    public function testPoolRejectsCrossPoolRelease(): void {
        $poolA=new PdoConnectionPool($this->sessionFactory());
        $poolB=new PdoConnectionPool($this->sessionFactory());
        $session=$poolB->acquire();
        // 误还到 A: 归属校验应拒绝, 不进入 A 的闲置
        $poolA->release($session);
        $fromA=$poolA->acquire();
        $this->assertNotSame($session,$fromA);     // A 未复用 $session → 被拒
        // $session 仍归 B 所有(未归还), 归还到正确的池后 B 才复用
        $session->release();
        $this->assertSame($session,$poolB->acquire());
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

    /**
     * 测试连接池工厂携带闲置上限
     * @return void
     */
    public function testPoolFactoryCarriesMaxIdle(): void {
        $factoryCalls=0;
        $pdo=new FakePdo();
        $factory=function() use ($pdo,&$factoryCalls) {
            $factoryCalls++;
            return new PdoConnectionSession($pdo,new MysqlDialect());
        };
        $pool=(new PdoConnectionPoolFactory($factory,2))->create();
        $a=$pool->acquire();
        $b=$pool->acquire();
        $c=$pool->acquire();
        $a->release();
        $b->release();
        $c->release();
        // 闲置上限 2: 只保留 a、b, c 被丢弃 → 下次获取需新建
        $x=$pool->acquire();
        $y=$pool->acquire();
        $z=$pool->acquire();
        $this->assertSame($b,$x);           // LIFO
        $this->assertSame($a,$y);
        $this->assertNotSame($c,$z);        // c 被丢弃, z 为新建
        $this->assertSame(4,$factoryCalls); // 首次 3 条 + 丢弃后的 1 条
    }

    /**
     * 测试连接配置的池闲置上限与工厂装配
     * @return void
     */
    public function testConnectionConfigPoolMaxIdle(): void {
        $config=new ConnectionConfig(poolMaxIdle:5);
        $this->assertSame(5,$config->getPoolMaxIdle());
        $factory=$config->createPoolFactory();
        $this->assertInstanceOf(ConnectionPoolFactoryInterface::class,$factory);
        $this->assertInstanceOf(ConnectionPoolInterface::class,$factory->create());
    }

}
