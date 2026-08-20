<?php

namespace Tests;

use PHPUnit\Framework\TestCase;

use base\Database\Connection\PdoConnectionManager;
use base\Database\Connection\PdoConnectionPool;
use base\Database\Connection\PdoConnectionSession;
use base\Database\Db;
use base\Database\Query\Query;
use base\Database\Sql\Dialect\MysqlDialect;
use Tests\Fixtures\FakePdo;

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
