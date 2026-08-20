<?php

namespace Tests;

use PHPUnit\Framework\TestCase;

use base\Database\Connection\PdoConnectionManager;
use base\Database\Connection\PdoConnectionPool;
use base\Database\Connection\PdoConnectionSession;
use base\Database\Coordinator\QueryCoordinator;
use base\Database\Middleware\QueryHandlerInterface;
use base\Database\Middleware\QueryMiddlewareInterface;
use base\Database\Query\Query;
use base\Database\Query\QueryContext;
use base\Database\Query\QueryContextInterface;
use base\Database\Result\ResultInterface;
use base\Database\Sql\Builder\QueryStatementBuilder;
use base\Database\Sql\Compiler\MysqlCompiler;
use base\Database\Sql\Dialect\MysqlDialect;
use Tests\Fixtures\FakePdo;

/**
 * 查询协调器端到端测试
 *
 * - 验证 Query → 构建/编译 → 中间件链 → 执行 → Result 全链路
 */
class DbalCoordinatorTest extends TestCase {

    /**
     * 创建连接管理器
     *
     * @access private
     * @param FakePdo $pdo 假连接
     * @return PdoConnectionManager
     */
    private function createManager(FakePdo $pdo): PdoConnectionManager {
        $factory=function() use ($pdo) {
            return new PdoConnectionSession($pdo,new MysqlDialect());
        };
        return new PdoConnectionManager(new PdoConnectionPool($factory),$factory);
    }

    /**
     * 测试查询全链路
     * @return void
     */
    public function testSelectThroughCoordinator(): void {
        $pdo=new FakePdo();
        $pdo->selectRows=[['id'=>1,'name'=>'a']];
        $manager=$this->createManager($pdo);
        $context=new QueryContext(Query::select()->from('users')->where('id',1));
        $coordinator=new QueryCoordinator($context,$manager,new MysqlCompiler());
        $result=$coordinator->query(new QueryStatementBuilder());
        $this->assertTrue($result->isSuccess());
        $this->assertSame([['id'=>1,'name'=>'a']],$result->getResults()->toArray());
        $this->assertSame('SELECT * FROM `users` WHERE `id` = ?',$pdo->executed[0]);
        $this->assertSame([1=>1],$pdo->bound);
    }

    /**
     * 测试写查询返回受影响行数
     * @return void
     */
    public function testWriteThroughCoordinator(): void {
        $pdo=new FakePdo();
        $pdo->affectedRows=2;
        $manager=$this->createManager($pdo);
        $context=new QueryContext(Query::update(['name'=>'b'])->from('users')->where('id',1));
        $coordinator=new QueryCoordinator($context,$manager,new MysqlCompiler());
        $result=$coordinator->query(new QueryStatementBuilder());
        $this->assertTrue($result->isSuccess());
        $this->assertSame(2,$result->getAffectedRows());
        $this->assertSame('UPDATE `users` SET `name` = ? WHERE `id` = ?',$pdo->executed[0]);
    }

    /**
     * 测试写查询也走连接池(复用池化会话, 未新建连接)
     * @return void
     */
    public function testWritesUsePooledConnection(): void {
        $pdo=new FakePdo();
        $pdo->affectedRows=1;
        $factoryCalls=0;
        $factory=function() use ($pdo,&$factoryCalls) {
            $factoryCalls++;
            return new PdoConnectionSession($pdo,new MysqlDialect());
        };
        $manager=new PdoConnectionManager(new PdoConnectionPool($factory),$factory);
        $context=new QueryContext(Query::update(array('name'=>'b'))->from('users')->where('id',1));
        $coordinator=new QueryCoordinator($context,$manager,new MysqlCompiler());
        $coordinator->query(new QueryStatementBuilder());
        $coordinator->query(new QueryStatementBuilder());
        // 两条写复用同一条池化会话, 会话工厂只调用一次
        $this->assertSame(1,$factoryCalls);
        $this->assertCount(2,$pdo->executed);
    }

    /**
     * 测试查询失败后会话被标记脏并丢弃(下次新建连接)
     * @return void
     */
    public function testDirtyConnectionDiscardedOnFailure(): void {
        $pdo=new FakePdo();
        $pdo->error='syntax error';
        $factoryCalls=0;
        $factory=function() use ($pdo,&$factoryCalls) {
            $factoryCalls++;
            return new PdoConnectionSession($pdo,new MysqlDialect());
        };
        $manager=new PdoConnectionManager(new PdoConnectionPool($factory),$factory);
        $context=new QueryContext(Query::select()->from('users'));
        $coordinator=new QueryCoordinator($context,$manager,new MysqlCompiler());
        $result=$coordinator->query(new QueryStatementBuilder());
        $this->assertFalse($result->isSuccess());
        // 失败 → 会话脏被丢弃, 再次查询需新建连接
        $coordinator->query(new QueryStatementBuilder());
        $this->assertSame(2,$factoryCalls);
    }

    /**
     * 测试中间件链按顺序执行
     * @return void
     */
    public function testMiddlewareChain(): void {
        $pdo=new FakePdo();
        $pdo->selectRows=[['id'=>1]];
        $manager=$this->createManager($pdo);
        $trace=[];
        $middleware1=new class($trace) implements QueryMiddlewareInterface {
            private array $trace;
            public function __construct(array &$trace) {
                $this->trace=&$trace;
            }
            public function process(QueryContextInterface $query,QueryHandlerInterface $next): ResultInterface {
                $this->trace[]='m1-before';
                $result=$next->handle($query);
                $this->trace[]='m1-after';
                return $result;
            }
        };
        $middleware2=new class($trace) implements QueryMiddlewareInterface {
            private array $trace;
            public function __construct(array &$trace) {
                $this->trace=&$trace;
            }
            public function process(QueryContextInterface $query,QueryHandlerInterface $next): ResultInterface {
                $this->trace[]='m2-before';
                $result=$next->handle($query);
                $this->trace[]='m2-after';
                return $result;
            }
        };
        $context=new QueryContext(Query::select()->from('users'));
        $coordinator=new QueryCoordinator($context,$manager,new MysqlCompiler(),[$middleware1,$middleware2]);
        $result=$coordinator->query(new QueryStatementBuilder());
        $this->assertTrue($result->isSuccess());
        $this->assertSame(['m1-before','m2-before','m2-after','m1-after'],$trace);
    }

}
