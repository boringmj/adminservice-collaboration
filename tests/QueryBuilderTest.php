<?php

namespace Tests;

use PHPUnit\Framework\TestCase;

use base\Database\Exception\QueryException;
use base\Database\Query\Query;
use base\Database\Query\QueryContext;
use base\Database\Sql\Builder\QueryStatementBuilder;
use base\Database\Type\QueryType;
use base\Database\Type\StatementType;

/**
 * 查询对象与构建器测试(方言无关)
 *
 * - 验证查询模型的构建状态与语句构建器的语义校验
 * - 不依赖任何具体方言/编译器
 */
class QueryBuilderTest extends TestCase {

    /**
     * 测试语句类型工厂
     * @return void
     */
    public function testQueryTypeFactories(): void {
        $this->assertSame(StatementType::SELECT,Query::select()->getType());
        $this->assertSame(StatementType::FIND,Query::find()->getType());
        $this->assertSame(StatementType::COUNT,Query::count()->getType());
        $this->assertSame(StatementType::INSERT,Query::insert()->getType());
        $this->assertSame(StatementType::UPDATE,Query::update()->getType());
        $this->assertSame(StatementType::DELETE,Query::delete()->getType());
    }

    /**
     * 测试带表限定的字段解析
     * @return void
     */
    public function testFieldParsingQualified(): void {
        $query=Query::select()->from('users')->field('users.id');
        $columns=$query->getColumns();
        $this->assertCount(1,$columns);
        $this->assertSame('users',$columns[0][0]->getTable());
        $this->assertSame('id',$columns[0][0]->getColumn());
        $this->assertNull($columns[0][1]);
    }

    /**
     * 测试字段解析去除反引号
     * @return void
     */
    public function testFieldBacktickStripping(): void {
        $query=Query::select()->from('users')->field('`users`.`id`');
        $field=$query->getColumns()[0][0];
        $this->assertSame('users',$field->getTable());
        $this->assertSame('id',$field->getColumn());
    }

    /**
     * 测试字段别名
     * @return void
     */
    public function testFieldAlias(): void {
        $query=Query::select()->from('users')->field('name','nickname');
        $columns=$query->getColumns();
        $this->assertSame('name',$columns[0][0]->getColumn());
        $this->assertSame('nickname',$columns[0][1]);
    }

    /**
     * 测试表名与别名的解析
     * @return void
     */
    public function testTableParsingAlias(): void {
        $query=Query::select()->from('users u');
        $this->assertSame('users',$query->getTable()->getName());
        $this->assertSame('u',$query->getTable()->getAlias());
    }

    /**
     * 测试多个条件并入 AND 根分组
     * @return void
     */
    public function testWhereBuildsAndGroup(): void {
        $query=Query::select()->from('users')->where('a',1)->where('b',2);
        $wheres=$query->getWheres();
        $this->assertCount(2,$wheres);
        $this->assertFalse($wheres[0]->isGroup());
        $this->assertSame('a',$wheres[0]->getField()->getColumn());
        $this->assertSame(1,$wheres[0]->getValue());
        $this->assertFalse($wheres[1]->isGroup());
        $this->assertSame('b',$wheres[1]->getField()->getColumn());
    }

    /**
     * 测试分组条件构建嵌套结构
     * @return void
     */
    public function testWhereGroupBuildsNested(): void {
        $query=Query::select()->from('users')
            ->where('a',1)
            ->whereGroup('OR',function($sub) {
                $sub->where('b',2)->where('c',3);
            });
        $wheres=$query->getWheres();
        $this->assertCount(2,$wheres);
        $this->assertTrue($wheres[1]->isGroup());
        $this->assertSame('OR',$wheres[1]->getConnector());
        $this->assertCount(2,$wheres[1]->getConditions());
    }

    /**
     * 测试空分组回调不产生条件
     * @return void
     */
    public function testWhereGroupEmptyIgnored(): void {
        $query=Query::select()->from('users')->whereGroup('OR',function($sub) {
            // 不添加任何条件
        });
        $this->assertFalse($query->hasWhere());
    }

    /**
     * 测试查询上下文推断读/写类型
     * @return void
     */
    public function testQueryContextInfersType(): void {
        $read=new QueryContext(Query::select()->from('users'));
        $this->assertSame(QueryType::READ,$read->getQueryType());
        $write=new QueryContext(Query::update(['a'=>1])->from('users')->where('id',1));
        $this->assertSame(QueryType::WRITE,$write->getQueryType());
    }

    /**
     * 测试构建器快照为不可变语句定义
     * @return void
     */
    public function testBuilderSnapshotsDefinition(): void {
        $builder=new QueryStatementBuilder();
        $query=Query::select()->from('users','u')->field('id');
        $definition=$builder->build($query);
        $this->assertSame(StatementType::SELECT,$definition->getType());
        $this->assertSame('users',$definition->getTable()->getName());
        $this->assertSame('u',$definition->getTable()->getAlias());
        $this->assertCount(1,$definition->getColumns());
    }

    /**
     * 测试插入数据行归一化
     * @return void
     */
    public function testInsertRowsNormalization(): void {
        $this->assertSame(array(array('a'=>1)),Query::insert(array('a'=>1))->getRows());
        $this->assertSame(array(array('a'=>1),array('a'=>2)),Query::insert(array(array('a'=>1),array('a'=>2)))->getRows());
    }

    /**
     * 测试查询条件存在性
     * @return void
     */
    public function testHasWhere(): void {
        $query=Query::select()->from('users');
        $this->assertFalse($query->hasWhere());
        $query->where('id',1);
        $this->assertTrue($query->hasWhere());
    }

    /**
     * 测试字段星号被忽略(表示全部字段)
     * @return void
     */
    public function testFieldStarIgnored(): void {
        $query=Query::select()->from('users')->field('*');
        $this->assertSame([],$query->getColumns());
    }

    /**
     * 测试关联条件为扁平字符串列表的负例
     * @return void
     */
    public function testJoinFlatListThrows(): void {
        $this->expectException(QueryException::class);
        $this->expectExceptionCode(100512);
        Query::select()->from('users')
            ->join('inner','orders',['a=x','b=y']);
    }

    /**
     * 测试标量操作符传入数组值的负例
     * @return void
     */
    public function testWhereArrayValueThrows(): void {
        $this->expectException(QueryException::class);
        $this->expectExceptionCode(100509);
        Query::select()->from('users')->where('a',[1,2]);
    }

    /**
     * 测试关联查询非法操作符的负例
     * @return void
     */
    public function testJoinInvalidOperatorThrows(): void {
        $this->expectException(QueryException::class);
        $this->expectExceptionCode(100512);
        Query::select()->from('users')
            ->join('left','orders o',[['u.id','XYZ','o.user_id']]);
    }

    /**
     * 测试关联查询标量右值(作为参数值存储)
     * @return void
     */
    public function testJoinScalarRight(): void {
        $query=Query::select()->from('users')
            ->join('inner','orders o',[['u.status','=',1]]);
        $joins=$query->getJoins();
        $this->assertCount(1,$joins);
        $this->assertSame(1,$joins[0]->getConditions()[0][2]);
    }

    /**
     * 测试更新无条件的负例
     * @return void
     */
    public function testUpdateWithoutWhereThrows(): void {
        $this->expectException(QueryException::class);
        $this->expectExceptionCode(100703);
        $builder=new QueryStatementBuilder();
        $builder->build(Query::update(['name'=>'b'])->from('users'));
    }

    /**
     * 测试删除无条件的负例
     * @return void
     */
    public function testDeleteWithoutWhereThrows(): void {
        $this->expectException(QueryException::class);
        $this->expectExceptionCode(100703);
        $builder=new QueryStatementBuilder();
        $builder->build(Query::delete()->from('users'));
    }

    /**
     * 测试非法操作符的负例
     * @return void
     */
    public function testInvalidOperatorThrows(): void {
        $this->expectException(QueryException::class);
        $this->expectExceptionCode(100508);
        Query::select()->from('users')->where('a',1,'BAD_OPERATOR');
    }

    /**
     * 测试插入空数据的负例
     * @return void
     */
    public function testInsertEmptyThrows(): void {
        $this->expectException(QueryException::class);
        $this->expectExceptionCode(100704);
        $builder=new QueryStatementBuilder();
        $builder->build(Query::insert()->from('users'));
    }

    /**
     * 测试未设置主表的负例
     * @return void
     */
    public function testMissingTableThrows(): void {
        $this->expectException(QueryException::class);
        $this->expectExceptionCode(100702);
        $builder=new QueryStatementBuilder();
        $builder->build(Query::select());
    }

}
