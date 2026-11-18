<?php

namespace Tests;

use PHPUnit\Framework\TestCase;

use base\Database\Connection\PdoConnectionManager;
use base\Database\Connection\PdoConnectionPool;
use base\Database\Connection\PdoConnectionSession;
use base\Database\Db;
use base\Database\Exception\TransactionException;
use base\Database\Query\Query;
use base\Database\Sql\Dialect\MysqlDialect;
use Fiber;
use ReflectionProperty;
use Tests\Fixtures\FakePdo;

use function gc_collect_cycles;

/**
 * 数据库入口测试
 *
 * - 验证 Query + Db 的公共 API: 执行、事务作用域(含嵌套)、手动事务
 */
class DbTest extends TestCase {

    /**
     * 创建基于 FakePdo 的数据库入口
     *
     * @access private
     * @param FakePdo $pdo 假连接
     * @return Db
     */
    private function createDb(FakePdo $pdo): Db {
        $factory=function() use ($pdo) {
            return new PdoConnectionSession($pdo,new MysqlDialect());
        };
        $manager=new PdoConnectionManager(new PdoConnectionPool($factory),$factory);
        return new Db($manager);
    }

    /**
     * 测试查询执行
     * @return void
     */
    public function testQuery(): void {
        $pdo=new FakePdo();
        $pdo->selectRows=[['id'=>1,'name'=>'a']];
        $db=$this->createDb($pdo);
        $result=$db->query(Query::select()->from('users')->where('id',1));
        $this->assertTrue($result->isSuccess());
        $this->assertSame([['id'=>1,'name'=>'a']],$result->getResults()->toArray());
        $this->assertSame('SELECT * FROM `users` WHERE `id` = ?',$pdo->executed[0]);
        $this->assertSame([1=>1],$pdo->bound);
    }

    /**
     * 测试写查询
     * @return void
     */
    public function testWriteQuery(): void {
        $pdo=new FakePdo();
        $pdo->affectedRows=2;
        $db=$this->createDb($pdo);
        $result=$db->query(Query::update(['name'=>'b'])->from('users')->where('id',1));
        $this->assertTrue($result->isSuccess());
        $this->assertSame(2,$result->getAffectedRows());
        $this->assertSame('UPDATE `users` SET `name` = ? WHERE `id` = ?',$pdo->executed[0]);
    }

    /**
     * 测试原生 SQL 执行
     * @return void
     */
    public function testRawSql(): void {
        $pdo=new FakePdo();
        $pdo->selectRows=[['name'=>'tables_count','Value'=>5]];
        $db=$this->createDb($pdo);
        $result=$db->raw('SHOW TABLES');
        $this->assertTrue($result->isSuccess());
        $this->assertSame('SHOW TABLES',$pdo->executed[0]);
        $this->assertSame([['name'=>'tables_count','Value'=>5]],$result->getResults()->toArray());
    }

    /**
     * 测试原生 SQL 使用命名占位符
     * @return void
     */
    public function testRawSqlWithNamedParams(): void {
        $pdo=new FakePdo();
        $pdo->selectRows=array(array('id'=>1,'name'=>'a'));
        $db=$this->createDb($pdo);
        $result=$db->raw(
            'SELECT * FROM users WHERE id = :id',
            array(':id'=>1)
        );
        $this->assertTrue($result->isSuccess());
        $this->assertSame('SELECT * FROM users WHERE id = :id',$pdo->executed[0]);
        $this->assertSame(1,$pdo->bound[':id']);
    }

    /**
     * 测试事务作用域
     * @return void
     */
    public function testTransaction(): void {
        $pdo=new FakePdo();
        $pdo->affectedRows=1;
        $db=$this->createDb($pdo);
        $db->transaction(function($db) {
            $db->query(Query::insert(['a'=>1])->from('users'));
            $db->query(Query::insert(['a'=>2])->from('users'));
        });
        $this->assertSame(['begin','commit'],$pdo->transactionCalls);
        $this->assertCount(2,$pdo->executed);
    }

    /**
     * 测试事务回调异常自动回滚
     * @return void
     */
    public function testTransactionRollbackOnError(): void {
        $pdo=new FakePdo();
        $db=$this->createDb($pdo);
        try {
            $db->transaction(function($db) {
                $db->query(Query::insert(['a'=>1])->from('users'));
                throw new \RuntimeException('boom');
            });
            $this->fail('预期抛出异常');
        } catch(\RuntimeException $e) {
            $this->assertSame('boom',$e->getMessage());
        }
        $this->assertSame(['begin','rollback'],$pdo->transactionCalls);
    }

    /**
     * 测试回滚失败时保留原始异常并标记连接为脏
     *
     * @return void
     */
    public function testTransactionRollbackFailurePreservesOriginalAndMarksDirty(): void {
        $pdo=new FakePdo();
        $pdo->rollbackError='Simulated rollback failure.';
        $created=array();
        $factory=function() use ($pdo,&$created) {
            $session=new PdoConnectionSession($pdo,new MysqlDialect());
            $created[]=$session;
            return $session;
        };
        $manager=new PdoConnectionManager(new PdoConnectionPool($factory),$factory);
        $db=new Db($manager);
        try {
            $db->transaction(static function(): void {
                throw new \RuntimeException('boom');
            });
            $this->fail('预期抛出异常');
        } catch(\RuntimeException $e) {
            $this->assertSame('boom',$e->getMessage());
        }
        $this->assertCount(1,$created);
        $this->assertTrue($created[0]->isDirty());
        $this->assertSame('begin',$pdo->transactionCalls[0]);
        $this->assertContains('rollback',$pdo->transactionCalls);
    }

    /**
     * 测试事务回调返回失败 Result 时自动回滚
     * @return void
     */
    public function testTransactionRollsBackOnFailedResult(): void {
        $pdo=new FakePdo();
        // 第一条写入成功, 第二条写入失败并返回失败 Result
        $pdo->errorOnExecution=2;
        $db=$this->createDb($pdo);
        $result=$db->transaction(function($db) {
            $db->query(Query::insert(['a'=>1])->from('users'));
            return $db->raw('UPDATE users SET a = 2');
        });
        $this->assertFalse($result->isSuccess());
        $this->assertStringContainsString('Simulated execution failure.',$result->getError());
        $this->assertSame(['begin','rollback'],$pdo->transactionCalls);
        $this->assertCount(1,$pdo->executed);
    }

    /**
     * 测试事务回调返回成功 Result 时正常提交
     * @return void
     */
    public function testTransactionCommitsSuccessfulResult(): void {
        $pdo=new FakePdo();
        $pdo->affectedRows=1;
        $db=$this->createDb($pdo);
        $result=$db->transaction(function($db) {
            return $db->raw('UPDATE users SET a = 1');
        });
        $this->assertTrue($result->isSuccess());
        $this->assertSame(['begin','commit'],$pdo->transactionCalls);
    }

    /**
     * 测试嵌套事务返回失败 Result 时回滚保存点并向外传播
     * @return void
     */
    public function testNestedTransactionRollsBackFailedResult(): void {
        $pdo=new FakePdo();
        $pdo->errorOnExecution=2;
        $db=$this->createDb($pdo);
        $result=$db->transaction(function($db) {
            $db->query(Query::insert(['a'=>1])->from('users'));
            return $db->transaction(function($db) {
                return $db->raw('UPDATE users SET a = 2');
            });
        });
        $this->assertFalse($result->isSuccess());
        $this->assertSame(['begin','rollback'],$pdo->transactionCalls);
        $this->assertSame(['SAVEPOINT sp_1','ROLLBACK TO SAVEPOINT sp_1'],$pdo->execCalls);
    }

    /**
     * 测试嵌套事务使用保存点
     * @return void
     */
    public function testNestedTransaction(): void {
        $pdo=new FakePdo();
        $db=$this->createDb($pdo);
        $db->transaction(function($db) {
            $db->query(Query::insert(['a'=>1])->from('users'));
            $db->transaction(function($db) {
                $db->query(Query::insert(['a'=>2])->from('users'));
            });
        });
        $this->assertSame(['begin','commit'],$pdo->transactionCalls);
        $this->assertSame(['SAVEPOINT sp_1','RELEASE SAVEPOINT sp_1'],$pdo->execCalls);
        $this->assertCount(2,$pdo->executed);
    }

    /**
     * 测试 raw 在事务内复用绑定会话(不借还池)
     * @return void
     */
    public function testRawInsideTransaction(): void {
        $pdo=new FakePdo();
        $db=$this->createDb($pdo);
        $db->transaction(function($db) {
            $db->raw('UPDATE users SET a = 1');
            $db->raw('SHOW TABLES');
        });
        $this->assertSame(['begin','commit'],$pdo->transactionCalls);
        $this->assertCount(2,$pdo->executed);
    }

    /**
     * 测试手动提交失败后标记连接为脏并解除绑定
     *
     * @return void
     */
    public function testManualCommitFailureMarksDirtyAndDetaches(): void {
        $pdo=new FakePdo();
        $pdo->commitError='Simulated commit failure.';
        $created=array();
        $factory=function() use ($pdo,&$created) {
            $session=new PdoConnectionSession($pdo,new MysqlDialect());
            $created[]=$session;
            return $session;
        };
        $manager=new PdoConnectionManager(new PdoConnectionPool($factory),$factory);
        $db=new Db($manager);
        $db->beginTransaction();
        try {
            $db->commit();
            $this->fail('预期抛出异常');
        } catch(TransactionException) {
        }
        $this->assertNull($db->getTransactionContext());
        $this->assertCount(1,$created);
        $this->assertTrue($created[0]->isDirty());
    }

    /**
     * 测试手动回滚失败后标记连接为脏并解除绑定
     *
     * @return void
     */
    public function testManualRollbackFailureMarksDirtyAndDetaches(): void {
        $pdo=new FakePdo();
        $pdo->rollbackError='Simulated rollback failure.';
        $created=array();
        $factory=function() use ($pdo,&$created) {
            $session=new PdoConnectionSession($pdo,new MysqlDialect());
            $created[]=$session;
            return $session;
        };
        $manager=new PdoConnectionManager(new PdoConnectionPool($factory),$factory);
        $db=new Db($manager);
        $db->beginTransaction();
        try {
            $db->rollBack();
            $this->fail('预期抛出异常');
        } catch(TransactionException) {
        }
        $this->assertNull($db->getTransactionContext());
        $this->assertCount(1,$created);
        $this->assertTrue($created[0]->isDirty());
    }

    /**
     * 测试手动事务
     * @return void
     */
    public function testManualTransaction(): void {
        $pdo=new FakePdo();
        $db=$this->createDb($pdo);
        $db->beginTransaction();
        $this->assertTrue($db->getTransactionContext()?->isActive());
        $db->query(Query::insert(['a'=>1])->from('users'));
        $db->commit();
        $this->assertNull($db->getTransactionContext());
        $this->assertSame(['begin','commit'],$pdo->transactionCalls);
    }

    /**
     * 测试释放事务会话会回滚未完成的手动事务
     *
     * @return void
     */
    public function testReleaseSessionRollsBackManualTransaction(): void {
        $pdo=new FakePdo();
        $db=$this->createDb($pdo);
        $db->beginTransaction();
        $this->assertTrue($db->getTransactionContext()?->isActive());

        $db->releaseSession();
        $this->assertNull($db->getTransactionContext());
        $this->assertSame(array('begin','rollback'),$pdo->transactionCalls);
    }

    /**
     * 测试事务回调遗留未闭合的手动事务层时全部回滚并抛出受控异常
     * @return void
     */
    public function testTransactionRejectsUnclosedManualLevels(): void {
        $pdo=new FakePdo();
        $db=$this->createDb($pdo);
        try {
            $db->transaction(static function($db): void {
                $db->beginTransaction();
            });
            $this->fail('预期抛出异常');
        } catch(TransactionException $e) {
            $this->assertSame(100607,$e->getCode());
        }
        $this->assertNull($db->getTransactionContext());
        $this->assertSame(array('begin','rollback'),$pdo->transactionCalls);
        $this->assertSame(array('SAVEPOINT sp_1','ROLLBACK TO SAVEPOINT sp_1'),$pdo->execCalls);
    }

    /**
     * 测试手动 commit 关闭全部未闭合层(保存点逐层释放后提交)
     * @return void
     */
    public function testManualCommitClosesAllOpenLevels(): void {
        $pdo=new FakePdo();
        $db=$this->createDb($pdo);
        $db->beginTransaction();
        $db->beginTransaction();
        $db->commit();
        $this->assertNull($db->getTransactionContext());
        $this->assertSame(array('begin','commit'),$pdo->transactionCalls);
        $this->assertSame(array('SAVEPOINT sp_1','RELEASE SAVEPOINT sp_1'),$pdo->execCalls);
    }

    /**
     * 测试手动 rollBack 关闭全部未闭合层(保存点逐层回滚后回滚)
     * @return void
     */
    public function testManualRollbackClosesAllOpenLevels(): void {
        $pdo=new FakePdo();
        $db=$this->createDb($pdo);
        $db->beginTransaction();
        $db->beginTransaction();
        $db->rollBack();
        $this->assertNull($db->getTransactionContext());
        $this->assertSame(array('begin','rollback'),$pdo->transactionCalls);
        $this->assertSame(array('SAVEPOINT sp_1','ROLLBACK TO SAVEPOINT sp_1'),$pdo->execCalls);
    }

    /**
     * 测试无活动事务时手动提交/回滚抛出受控异常
     * @return void
     */
    public function testManualCommitAndRollbackWithoutTransactionThrows(): void {
        $pdo=new FakePdo();
        $db=$this->createDb($pdo);
        foreach(array('commit','rollBack') as $method) {
            try {
                $db->{$method}();
                $this->fail($method.' 无活动事务应抛出受控异常');
            } catch(TransactionException $e) {
                $this->assertSame(100604,$e->getCode(),$method);
            }
        }
        $this->assertSame(array(),$pdo->transactionCalls);
    }

    /**
     * 测试: 嵌套请求作用域的事务会话互不共享
     *
     * @return void
     */
    public function testRequestScopeIsolatesTransactionSessions(): void {
        $pdo=new FakePdo();
        $db=$this->createDb($pdo);
        $previous=Db::setRequestScope('outer');
        try {
            $db->beginTransaction();
            Db::setRequestScope('inner');
            $db->beginTransaction();
            // 内层请求结束: 只释放内层会话
            $db->releaseCurrentSession();
            Db::setRequestScope('outer');
            $db->commit();
            $this->assertSame(array('begin','begin','rollback','commit'),$pdo->transactionCalls);
            $this->assertNull($db->getTransactionContext());
        } finally {
            $db->releaseSession();
            Db::setRequestScope($previous);
        }
    }

    /**
     * 测试: 两个 Fiber 的事务会话互不共享
     *
     * @return void
     */
    public function testFiberTransactionSessionsAreIsolated(): void {
        $pdo=new FakePdo();
        $db=$this->createDb($pdo);
        $context_a=null;
        $context_b=null;

        $fiber_a=new Fiber(function() use ($db,&$context_a): void {
            $db->beginTransaction();
            $context_a=$db->getTransactionContext();
            Fiber::suspend();
            $db->rollBack();
        });
        $fiber_b=new Fiber(function() use ($db,&$context_b): void {
            $db->beginTransaction();
            $context_b=$db->getTransactionContext();
            Fiber::suspend();
            $db->rollBack();
        });

        $this->assertNull($db->getTransactionContext(),'主执行流不应有事务');
        $fiber_a->start();
        $fiber_b->start();
        $this->assertNotNull($context_a);
        $this->assertNotNull($context_b);
        $this->assertNotSame($context_a,$context_b);
        $this->assertTrue($context_a->isActive());
        $this->assertTrue($context_b->isActive());
        $this->assertNull($db->getTransactionContext(),'主执行流仍不应有事务');

        $fiber_a->resume();
        $fiber_b->resume();
        $this->assertNull($db->getTransactionContext());
    }

    /**
     * 测试: 被放弃的 Fiber 残留会话与作用域在下一次写入时回收
     *
     * @return void
     */
    public function testAbandonedFiberStateIsPrunedOnNextWrite(): void {
        $pdo=new FakePdo();
        $db=$this->createDb($pdo);
        $fiber=new Fiber(function() use ($db): void {
            $db->beginTransaction();
            Db::setRequestScope('abandoned');
        });
        $fiber->start();
        unset($fiber);
        gc_collect_cycles();

        // 主流程新开事务触发清理: 残留会话应回滚, 残留作用域应删除
        $db->beginTransaction();
        $db->commit();
        $this->assertSame(
            array('begin','rollback','begin','commit'),
            $pdo->transactionCalls,
            '被放弃执行流的未完成事务应被回滚'
        );

        $scopes=new ReflectionProperty(Db::class,'scopes');
        $scopes->setAccessible(true);
        $this->assertSame(array(),$scopes->getValue(),'被放弃执行流的作用域应被回收');
    }

    /**
     * 测试 raw 执行事务控制语句时连接被标记脏并丢弃
     * @return void
     */
    public function testRawTransactionControlDiscardsConnection(): void {
        foreach(array('BEGIN','COMMIT','ROLLBACK','START TRANSACTION','SAVEPOINT sp_raw','RELEASE SAVEPOINT sp_raw') as $sql) {
            $pdo=new FakePdo();
            $factoryCalls=0;
            $factory=function() use ($pdo,&$factoryCalls) {
                $factoryCalls++;
                return new PdoConnectionSession($pdo,new MysqlDialect());
            };
            $manager=new PdoConnectionManager(new PdoConnectionPool($factory),$factory);
            $db=new Db($manager);
            $db->raw($sql);
            $db->raw('SHOW TABLES');
            // 事务/保存点状态属于会话状态, 不能把连接归还连接池复用
            $this->assertSame(2,$factoryCalls,$sql);
        }
    }

    /**
     * 测试 raw 执行修改会话状态的 SQL(SET)后连接被标记脏并丢弃
     * @return void
     */
    public function testRawSessionStateSqlDiscardsConnection(): void {
        $pdo=new FakePdo();
        $factoryCalls=0;
        $factory=function() use ($pdo,&$factoryCalls) {
            $factoryCalls++;
            return new PdoConnectionSession($pdo,new MysqlDialect());
        };
        $manager=new PdoConnectionManager(new PdoConnectionPool($factory),$factory);
        $db=new Db($manager);
        $db->raw("SET SESSION sql_mode = 'STRICT_ALL_TABLES'");
        $db->raw('SHOW TABLES');
        // SET 修改会话状态 → 该连接被丢弃; 下一条 raw 需新建连接
        $this->assertSame(2,$factoryCalls);
    }

}
