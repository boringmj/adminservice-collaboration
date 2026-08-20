<?php

namespace Tests;

use PHPUnit\Framework\TestCase;

use base\Database\Exception\CompilerException;
use base\Database\Exception\QueryException;
use base\Database\Query\Query;
use base\Database\Sql\Builder\QueryStatementBuilder;
use base\Database\Sql\Compiler\CompiledStatement;
use base\Database\Sql\Definition\Field;
use base\Database\Sql\Definition\Literal;
use base\Database\Sql\Compiler\CompileModeSet;
use base\Database\Sql\Compiler\CompilerContext;
use base\Database\Sql\Compiler\MysqlCompiler;
use base\Database\Sql\Dialect\MysqlDialect;
use base\Database\Sql\Type\CompileMode;
use Tests\Fixtures\FakeDialect;

/**
 * MySQL 编译器测试
 *
 * - 验证语义查询对象到 MySQL SQL 的编译结果
 * - 断言 SQL 字符串与参数列表
 */
class MysqlCompilerTest extends TestCase {

    /**
     * 编译查询对象
     *
     * @access private
     * @param Query $query 查询对象
     * @param int $mode 编译模式
     * @param string $prefix 表前缀
     * @return CompiledStatement
     * @throws QueryException
     */
    private function compile(Query $query,int $mode=0,string $prefix=''): CompiledStatement {
        $context=new CompilerContext(new MysqlDialect(),new CompileModeSet($mode),$prefix);
        $builder=new QueryStatementBuilder();
        $compiler=new MysqlCompiler();
        return $compiler->compile($builder->build($query),$context);
    }

    /**
     * 测试查询全部字段
     * @return void
     */
    public function testSelectAll(): void {
        $statement=$this->compile(Query::select()->from('users'));
        $this->assertSame('SELECT * FROM `users`',$statement->getSql());
        $this->assertSame([],$statement->getParams());
    }

    /**
     * 测试查询字段与别名
     * @return void
     */
    public function testSelectFields(): void {
        $query=Query::select()->from('users')->field('id')->field(['name'=>'nickname']);
        $statement=$this->compile($query);
        $this->assertSame(
            'SELECT `id`, `name` AS `nickname` FROM `users`',
            $statement->getSql()
        );
        $this->assertSame([],$statement->getParams());
    }

    /**
     * 测试带表限定的字段
     * @return void
     */
    public function testQualifiedField(): void {
        $statement=$this->compile(Query::select()->from('users')->field('users.id'));
        $this->assertSame('SELECT `users`.`id` FROM `users`',$statement->getSql());
    }

    /**
     * 测试逗号分隔字段
     * @return void
     */
    public function testCommaSeparatedFields(): void {
        $statement=$this->compile(Query::select()->from('users')->field('id,name'));
        $this->assertSame('SELECT `id`, `name` FROM `users`',$statement->getSql());
    }

    /**
     * 测试普通条件
     * @return void
     */
    public function testWhereEquals(): void {
        $statement=$this->compile(Query::select()->from('users')->where('age',18,'>='));
        $this->assertSame('SELECT * FROM `users` WHERE `age` >= ?',$statement->getSql());
        $this->assertSame([18],$statement->getParams());
    }

    /**
     * 测试带表限定的条件
     * @return void
     */
    public function testWhereQualified(): void {
        $statement=$this->compile(Query::select()->from('users')->where('users.id',1));
        $this->assertSame('SELECT * FROM `users` WHERE `users`.`id` = ?',$statement->getSql());
        $this->assertSame([1],$statement->getParams());
    }

    /**
     * 测试 IN 条件
     * @return void
     */
    public function testWhereIn(): void {
        $statement=$this->compile(Query::select()->from('users')->whereIn('status',[1,2,3]));
        $this->assertSame('SELECT * FROM `users` WHERE `status` IN (?, ?, ?)',$statement->getSql());
        $this->assertSame([1,2,3],$statement->getParams());
    }

    /**
     * 测试 NOT IN 条件
     * @return void
     */
    public function testWhereNotIn(): void {
        $statement=$this->compile(Query::select()->from('users')->whereNotIn('status',[1,2]));
        $this->assertSame('SELECT * FROM `users` WHERE `status` NOT IN (?, ?)',$statement->getSql());
        $this->assertSame([1,2],$statement->getParams());
    }

    /**
     * 测试 IS NULL 条件
     * @return void
     */
    public function testWhereNull(): void {
        $statement=$this->compile(Query::select()->from('users')->whereNull('deleted_at'));
        $this->assertSame('SELECT * FROM `users` WHERE `deleted_at` IS NULL',$statement->getSql());
        $this->assertSame([],$statement->getParams());
    }

    /**
     * 测试 BETWEEN 条件
     * @return void
     */
    public function testWhereBetween(): void {
        $statement=$this->compile(Query::select()->from('users')->whereBetween('age',18,60));
        $this->assertSame('SELECT * FROM `users` WHERE `age` BETWEEN ? AND ?',$statement->getSql());
        $this->assertSame([18,60],$statement->getParams());
    }

    /**
     * 测试分组条件(OR)
     * @return void
     */
    public function testWhereGroup(): void {
        $query=Query::select()->from('users')
            ->where('a',1)
            ->whereGroup('OR',function($sub) {
                $sub->where('b',2)->where('c',3);
            });
        $statement=$this->compile($query);
        $this->assertSame(
            'SELECT * FROM `users` WHERE `a` = ? AND (`b` = ? OR `c` = ?)',
            $statement->getSql()
        );
        $this->assertSame([1,2,3],$statement->getParams());
    }

    /**
     * 测试排序/分组/限制/偏移
     * @return void
     */
    public function testOrderGroupLimitOffset(): void {
        $query=Query::select()->from('users')
            ->order('id','DESC')
            ->group('type')
            ->limit(10,20);
        $statement=$this->compile($query);
        $this->assertSame(
            'SELECT * FROM `users` GROUP BY `type` ORDER BY `id` DESC LIMIT 10 OFFSET 20',
            $statement->getSql()
        );
        $this->assertSame([],$statement->getParams());
    }

    /**
     * 测试去重
     * @return void
     */
    public function testDistinct(): void {
        $statement=$this->compile(Query::select()->from('users')->distinct()->field('name'));
        $this->assertSame('SELECT DISTINCT `name` FROM `users`',$statement->getSql());
    }

    /**
     * 测试关联查询
     * @return void
     */
    public function testJoin(): void {
        $query=Query::select()->from('users','u')
            ->join('left','orders o',[
                ['u.id','=','orders.user_id'],
                ['o.status','=','u.status'],
            ]);
        $statement=$this->compile($query);
        $this->assertSame(
            'SELECT * FROM `users` `u` LEFT JOIN `orders` `o` ON `u`.`id` = `orders`.`user_id` AND `o`.`status` = `u`.`status`',
            $statement->getSql()
        );
        $this->assertSame([],$statement->getParams());
    }

    /**
     * 测试关联查询标量右值绑定为参数
     * @return void
     */
    public function testJoinScalarRight(): void {
        $query=Query::select()->from('users','u')
            ->join('inner','orders o',[['u.status','=',1]]);
        $statement=$this->compile($query);
        $this->assertSame(
            'SELECT * FROM `users` `u` INNER JOIN `orders` `o` ON `u`.`status` = ?',
            $statement->getSql()
        );
        $this->assertSame([1],$statement->getParams());
    }

    /**
     * 测试关联查询字符串字面量右值绑定为参数
     * @return void
     */
    public function testJoinStringLiteral(): void {
        $query=Query::select()->from('users','u')
            ->join('inner','orders o',[['o.status','=',Literal::of('active')]]);
        $statement=$this->compile($query);
        $this->assertSame(
            'SELECT * FROM `users` `u` INNER JOIN `orders` `o` ON `o`.`status` = ?',
            $statement->getSql()
        );
        $this->assertSame(['active'],$statement->getParams());
    }

    /**
     * 测试畸形插入行的负例(非数组)
     * @return void
     */
    public function testInsertMalformedRowsThrows(): void {
        $this->expectException(QueryException::class);
        $this->expectExceptionCode(100504);
        $this->compile(Query::insert()->rows(['foo'])->from('users'));
    }

    /**
     * 测试写语句带行锁的负例(MySQL 写语句不支持锁子句)
     * @return void
     */
    public function testLockOnWriteThrows(): void {
        $this->expectException(QueryException::class);
        $this->expectExceptionCode(100515);
        $this->compile(Query::update(['name'=>'b'])->from('users')->where('id',1)->lock());
    }

    /**
     * 测试去重统计无列的负例
     * @return void
     */
    public function testCountDistinctWithoutColumnsThrows(): void {
        $this->expectException(QueryException::class);
        $this->expectExceptionCode(100504);
        $this->compile(Query::count()->from('users')->distinct());
    }

    /**
     * 测试非法标识符在编译期被方言拒绝
     * @return void
     */
    public function testInvalidIdentifierRejectedAtCompile(): void {
        $this->expectException(QueryException::class);
        $this->expectExceptionCode(100517);
        $this->compile(Query::select()->from('users')->order(Field::column('bad name')));
    }

    /**
     * 测试单条查询自动附加 LIMIT 1
     * @return void
     */
    public function testFind(): void {
        $statement=$this->compile(Query::find()->from('users')->where('id',5));
        $this->assertSame('SELECT * FROM `users` WHERE `id` = ? LIMIT 1',$statement->getSql());
        $this->assertSame([5],$statement->getParams());
    }

    /**
     * 测试统计
     * @return void
     */
    public function testCount(): void {
        $statement=$this->compile(Query::count()->from('users')->where('status',1));
        $this->assertSame(
            'SELECT COUNT(*) AS `__count` FROM `users` WHERE `status` = ?',
            $statement->getSql()
        );
        $this->assertSame([1],$statement->getParams());
    }

    /**
     * 测试分组统计附带分组字段
     * @return void
     */
    public function testCountWithGroup(): void {
        $statement=$this->compile(Query::count()->from('users')->group('type'));
        $this->assertSame(
            'SELECT COUNT(*) AS `__count`, `type` FROM `users` GROUP BY `type`',
            $statement->getSql()
        );
    }

    /**
     * 测试去重统计
     * @return void
     */
    public function testCountDistinct(): void {
        $statement=$this->compile(Query::count()->from('users')->distinct()->field('type'));
        $this->assertSame(
            'SELECT COUNT(DISTINCT `type`) AS `__count` FROM `users`',
            $statement->getSql()
        );
    }

    /**
     * 测试单行插入
     * @return void
     */
    public function testInsert(): void {
        $statement=$this->compile(Query::insert(['name'=>'a','age'=>18])->from('users'));
        $this->assertSame(
            'INSERT INTO `users` (`name`, `age`) VALUES (?, ?)',
            $statement->getSql()
        );
        $this->assertSame(['a',18],$statement->getParams());
    }

    /**
     * 测试多行插入
     * @return void
     */
    public function testInsertMultiRow(): void {
        $query=Query::insert([
            ['name'=>'a','age'=>1],
            ['name'=>'b','age'=>2],
        ])->from('users');
        $statement=$this->compile($query);
        $this->assertSame(
            'INSERT INTO `users` (`name`, `age`) VALUES (?, ?), (?, ?)',
            $statement->getSql()
        );
        $this->assertSame(['a',1,'b',2],$statement->getParams());
    }

    /**
     * 测试更新
     * @return void
     */
    public function testUpdate(): void {
        $statement=$this->compile(Query::update(['name'=>'b'])->from('users')->where('id',1));
        $this->assertSame(
            'UPDATE `users` SET `name` = ? WHERE `id` = ?',
            $statement->getSql()
        );
        $this->assertSame(['b',1],$statement->getParams());
    }

    /**
     * 测试删除
     * @return void
     */
    public function testDelete(): void {
        $statement=$this->compile(Query::delete()->from('users')->where('id',1));
        $this->assertSame('DELETE FROM `users` WHERE `id` = ?',$statement->getSql());
        $this->assertSame([1],$statement->getParams());
    }

    /**
     * 测试行锁
     * @return void
     */
    public function testLock(): void {
        $query=Query::select()->from('users')->where('id',1);
        $this->assertSame(
            'SELECT * FROM `users` WHERE `id` = ? FOR UPDATE',
            $this->compile($query->lock())->getSql()
        );
        $this->assertSame(
            'SELECT * FROM `users` WHERE `id` = ? LOCK IN SHARE MODE',
            $this->compile($query->lock('shared'))->getSql()
        );
    }

    /**
     * 测试表前缀
     * @return void
     */
    public function testTablePrefix(): void {
        $statement=$this->compile(Query::select()->from('users'),0,'pre_');
        $this->assertSame('SELECT * FROM `pre_users`',$statement->getSql());
    }

    /**
     * 测试内联参数模式
     * @return void
     */
    public function testInlineParam(): void {
        $query=Query::select()->from('users')
            ->where('age',18)
            ->where('name','bob')
            ->whereNull('deleted_at');
        $statement=$this->compile($query,CompileMode::INLINE_PARAM);
        $this->assertSame(
            "SELECT * FROM `users` WHERE `age` = 18 AND `name` = 'bob' AND `deleted_at` IS NULL",
            $statement->getSql()
        );
        $this->assertSame([],$statement->getParams());
    }

    /**
     * 测试内联参数模式需要方言具备转义能力
     * @return void
     */
    public function testInlineParamRequiresQuotingCapability(): void {
        $context=new CompilerContext(new FakeDialect(),CompileModeSet::with(CompileMode::INLINE_PARAM));
        $builder=new QueryStatementBuilder();
        $compiler=new MysqlCompiler();
        $this->expectException(CompilerException::class);
        $this->expectExceptionCode(100508);
        $compiler->compile($builder->build(Query::select()->from('users')->where('id',1)),$context);
    }

    /**
     * 测试占位符跟随方言而非硬编码
     * @return void
     */
    public function testDialectPlaceholderIsUsed(): void {
        $context=new CompilerContext(new FakeDialect());
        $builder=new QueryStatementBuilder();
        $compiler=new MysqlCompiler();
        $statement=$compiler->compile($builder->build(
            Query::select()->from('users')->where('id',1)->where('age',2)
        ),$context);
        $this->assertSame(
            'SELECT * FROM `users` WHERE `id` = $1 AND `age` = $2',
            $statement->getSql()
        );
        $this->assertSame([1,2],$statement->getParams());
    }

}
