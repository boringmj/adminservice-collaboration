<?php

namespace Tests;

use PHPUnit\Framework\TestCase;

use base\Database\Connection\PdoConnectionManager;
use base\Database\Connection\PdoConnectionPool;
use base\Database\Connection\PdoConnectionSession;
use base\Database\Db;
use base\Database\Query\QueryBuilder;
use base\Database\Sql\Dialect\MysqlDialect;
use Tests\Fixtures\FakePdo;

/**
 * 流式查询构建器测试
 *
 * - 验证 QueryBuilder(裸查询入口)的读/写终端方法
 */
class QueryBuilderTest extends TestCase {

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
     * 测试 get 返回行集合
     * @return void
     */
    public function testGet(): void {
        $pdo=new FakePdo();
        $pdo->selectRows=[['id'=>1,'name'=>'a'],['id'=>2,'name'=>'b']];
        $builder=new QueryBuilder($this->createDb($pdo),'users');
        $rows=$builder->where('status',1)->get();
        $this->assertSame([['id'=>1,'name'=>'a'],['id'=>2,'name'=>'b']],$rows);
        $this->assertSame('SELECT * FROM `users` WHERE `status` = ?',$pdo->executed[0]);
    }

    /**
     * 测试 first 返回单行
     * @return void
     */
    public function testFirst(): void {
        $pdo=new FakePdo();
        $pdo->selectRows=[['id'=>1,'name'=>'a']];
        $builder=new QueryBuilder($this->createDb($pdo),'users');
        $row=$builder->where('id',1)->first();
        $this->assertSame(['id'=>1,'name'=>'a'],$row);
        $this->assertSame('SELECT * FROM `users` WHERE `id` = ? LIMIT 1',$pdo->executed[0]);
    }

    /**
     * 测试 count 统计
     * @return void
     */
    public function testCount(): void {
        $pdo=new FakePdo();
        $pdo->selectRows=[['__count'=>5]];
        $builder=new QueryBuilder($this->createDb($pdo),'users');
        $this->assertSame(5,$builder->where('status',1)->count());
        $this->assertSame('SELECT COUNT(*) AS `__count` FROM `users` WHERE `status` = ?',$pdo->executed[0]);
    }

    /**
     * 测试 value/pluck
     * @return void
     */
    public function testValueAndPluck(): void {
        $pdo=new FakePdo();
        $pdo->selectRows=[['id'=>1,'name'=>'a']];
        $builder=new QueryBuilder($this->createDb($pdo),'users');
        $this->assertSame('a',$builder->where('id',1)->value('name'));
        $pdo->selectRows=[['name'=>'a'],['name'=>'b']];
        $this->assertSame(['a','b'],(new QueryBuilder($this->createDb($pdo),'users'))->pluck('name'));
    }

    /**
     * 测试 insert 返回自增主键
     * @return void
     */
    public function testInsert(): void {
        $pdo=new FakePdo();
        $pdo->affectedRows=1;
        $builder=new QueryBuilder($this->createDb($pdo),'users');
        $id=$builder->insert(['name'=>'a','age'=>20]);
        $this->assertSame(1,$id);
        $this->assertSame('INSERT INTO `users` (`name`, `age`) VALUES (?, ?)',$pdo->executed[0]);
    }

    /**
     * 测试 update 返回受影响行数
     * @return void
     */
    public function testUpdate(): void {
        $pdo=new FakePdo();
        $pdo->affectedRows=2;
        $builder=new QueryBuilder($this->createDb($pdo),'users');
        $affected=$builder->where('status',0)->update(['name'=>'x']);
        $this->assertSame(2,$affected);
        $this->assertSame('UPDATE `users` SET `name` = ? WHERE `status` = ?',$pdo->executed[0]);
    }

    /**
     * 测试 delete 返回受影响行数
     * @return void
     */
    public function testDelete(): void {
        $pdo=new FakePdo();
        $pdo->affectedRows=1;
        $builder=new QueryBuilder($this->createDb($pdo),'users');
        $affected=$builder->where('id',1)->delete();
        $this->assertSame(1,$affected);
        $this->assertSame('DELETE FROM `users` WHERE `id` = ?',$pdo->executed[0]);
    }

    /**
     * 测试门面 connection() 入口(选择连接)
     * @return void
     */
    public function testFacadeConnectionEntry(): void {
        // connection('default') 返回绑定默认连接的 Db 实例(继承 DbFacade)
        $facade=\AdminService\Db::connection('default');
        $this->assertInstanceOf(\AdminService\Db::class,$facade);
        $builder=$facade->table('users');
        $this->assertInstanceOf(QueryBuilder::class,$builder);
        // 默认连接入口(继承实例方法)
        $this->assertInstanceOf(QueryBuilder::class,\AdminService\Db::connection()->table('users'));
    }

    /**
     * 测试分页
     * @return void
     */
    public function testPaginate(): void {
        $pdo=new FakePdo();
        $pdo->selectRowsQueue=[
            [['__count'=>5]],
            [['id'=>1,'name'=>'a'],['id'=>2,'name'=>'b']],
        ];
        $builder=new QueryBuilder($this->createDb($pdo),'users');
        $page=$builder->where('status',1)->paginate(2,1);
        $this->assertSame(5,$page['total']);
        $this->assertCount(2,$page['items']);
        $this->assertSame(3,$page['last_page']);
        $this->assertSame('SELECT COUNT(*) AS `__count` FROM `users` WHERE `status` = ?',$pdo->executed[0]);
    }

}
