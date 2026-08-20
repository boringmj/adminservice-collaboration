<?php

namespace Tests;

use PHPUnit\Framework\TestCase;

use base\Database\Execution\PdoSqlExecutor;
use base\Database\Query\Query;
use base\Database\Sql\Builder\QueryStatementBuilder;
use base\Database\Sql\Compiler\CompiledStatement;
use base\Database\Sql\Compiler\CompileModeSet;
use base\Database\Sql\Compiler\CompilerContext;
use base\Database\Sql\Compiler\MysqlCompiler;
use base\Database\Sql\Dialect\MysqlDialect;
use Tests\Fixtures\FakePdo;

/**
 * SQL 执行器测试
 *
 * - 验证编译后语句的 PDO 执行与结果产出
 */
class DbalExecutorTest extends TestCase {

    /**
     * 编译查询对象
     *
     * @access private
     * @param Query $query 查询对象
     * @return CompiledStatement
     */
    private function compile(Query $query): CompiledStatement {
        $context=new CompilerContext(new MysqlDialect());
        $builder=new QueryStatementBuilder();
        $compiler=new MysqlCompiler();
        return $compiler->compile($builder->build($query),$context);
    }

    /**
     * 测试查询语句返回行集合
     * @return void
     */
    public function testExecuteSelect(): void {
        $pdo=new FakePdo();
        $pdo->selectRows=[['id'=>1,'name'=>'a'],['id'=>2,'name'=>'b']];
        $executor=new PdoSqlExecutor($pdo);
        $result=$executor->execute($this->compile(Query::select()->from('users')));
        $this->assertTrue($result->isSuccess());
        $this->assertSame(2,$result->getResults()->count());
        $this->assertSame('SELECT * FROM `users`',$result->getSql());
        $this->assertSame([],$result->getParams());
        $this->assertSame([['id'=>1,'name'=>'a'],['id'=>2,'name'=>'b']],$result->getResults()->toArray());
    }

    /**
     * 测试写语句返回受影响行数
     * @return void
     */
    public function testExecuteWrite(): void {
        $pdo=new FakePdo();
        $pdo->affectedRows=1;
        $executor=new PdoSqlExecutor($pdo);
        $result=$executor->execute($this->compile(
            Query::update(['name'=>'b'])->from('users')->where('id',1)
        ));
        $this->assertTrue($result->isSuccess());
        $this->assertSame(1,$result->getAffectedRows());
        $this->assertSame('UPDATE `users` SET `name` = ? WHERE `id` = ?',$pdo->executed[0]);
        $this->assertSame([1=>'b',2=>1],$pdo->bound);
    }

    /**
     * 测试执行错误返回失败结果
     * @return void
     */
    public function testExecuteError(): void {
        $pdo=new FakePdo();
        $pdo->error='syntax error';
        $executor=new PdoSqlExecutor($pdo);
        $result=$executor->execute($this->compile(Query::select()->from('users')));
        $this->assertFalse($result->isSuccess());
        $this->assertSame('syntax error',$result->getError());
        $this->assertSame(0,$result->getAffectedRows());
    }

}
