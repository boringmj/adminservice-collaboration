<?php

namespace Tests;

use PHPUnit\Framework\TestCase;

use base\Database\Connection\PdoConnectionManager;
use base\Database\Connection\PdoConnectionPool;
use base\Database\Connection\PdoConnectionSession;
use base\Database\Db;
use base\Database\Sql\Dialect\MysqlDialect;
use base\ModelCollection;
use Tests\Fixtures\Post;
use Tests\Fixtures\SystemInfo;
use Tests\Fixtures\User;
use Tests\Fixtures\FakePdo;

/**
 * ORM 测试
 *
 * - 验证 Model + ModelQueryBuilder + ModelCollection 的查询/水合/CRUD
 */
class ModelTest extends TestCase {

    private FakePdo $pdo;

    /**
     * 每个测试前注入 FakePdo 数据库入口
     * @return void
     */
    protected function setUp(): void {
        $this->pdo=new FakePdo();
        User::setDb($this->createDb($this->pdo));
        SystemInfo::setDb($this->createDb($this->pdo));
        Post::setDb($this->createDb($this->pdo));
    }

    /**
     * 每个测试后清理, 避免污染
     * @return void
     */
    protected function tearDown(): void {
        User::setDb(null);
        SystemInfo::setDb(null);
        Post::setDb(null);
    }

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
     * 测试查询返回模型集合
     * @return void
     */
    public function testQueryReturnsCollection(): void {
        $this->pdo->selectRows=[
            ['id'=>1,'name'=>'张三','age'=>20,'status'=>1],
            ['id'=>2,'name'=>'李四','age'=>21,'status'=>2],
        ];
        $users=User::query()->where('age',18,'>=')->get();
        $this->assertInstanceOf(ModelCollection::class,$users);
        $this->assertCount(2,$users);
        $this->assertInstanceOf(User::class,$users->first());
        $this->assertSame('张三',$users->first()->name);
        $this->assertSame(['张三','李四'],$users->pluck('name'));
        $this->assertSame('SELECT * FROM `users` WHERE `age` >= ?',$this->pdo->executed[0]);
    }

    /**
     * 测试静态链式调用
     * @return void
     */
    public function testStaticChaining(): void {
        $this->pdo->selectRows=[];
        User::where('status',1)->orderBy('id','DESC')->get();
        $this->assertSame(
            'SELECT * FROM `users` WHERE `status` = ? ORDER BY `id` DESC',
            $this->pdo->executed[0]
        );
    }

    /**
     * 测试按主键查找
     * @return void
     */
    public function testFind(): void {
        $this->pdo->selectRows=[['id'=>1,'name'=>'张三','age'=>20,'status'=>1]];
        $user=User::find(1);
        $this->assertInstanceOf(User::class,$user);
        $this->assertSame(1,$user->getKey());
        $this->assertSame('SELECT * FROM `users` WHERE `id` = ? LIMIT 1',$this->pdo->executed[0]);
    }

    /**
     * 测试查找不存在返回 null
     * @return void
     */
    public function testFindReturnsNull(): void {
        $this->pdo->selectRows=[];
        $this->assertNull(User::find(999));
    }

    /**
     * 测试统计数量
     * @return void
     */
    public function testCount(): void {
        $this->pdo->selectRows=[['__count'=>3]];
        $this->assertSame(3,User::query()->where('status',1)->count());
    }

    /**
     * 测试创建记录
     * @return void
     */
    public function testCreate(): void {
        $this->pdo->affectedRows=1;
        $user=User::create(['name'=>'张三','age'=>20,'status'=>1]);
        $this->assertInstanceOf(User::class,$user);
        $this->assertSame('1',$user->getKey());
        $this->assertTrue($user->exists());
        // 白名单字段 + 自动时间戳 + 自增主键
        $this->assertSame('张三',$user->getAttribute('name'));
        $this->assertNotNull($user->created_at);
        $this->assertNotNull($user->updated_at);
        $this->assertSame(
            'INSERT INTO `users` (`name`, `age`, `status`, `created_at`, `updated_at`) VALUES (?, ?, ?, ?, ?)',
            $this->pdo->executed[0]
        );
    }

    /**
     * 测试创建受 fillable 白名单约束
     * @return void
     */
    public function testCreateRespectsFillable(): void {
        $this->pdo->affectedRows=1;
        $user=User::create(['name'=>'张三','age'=>20,'status'=>1,'secret'=>'x']);
        $this->assertNull($user->secret);
        // 非白名单字段未设置, 白名单字段已设置
        $this->assertArrayNotHasKey('secret',$user->getAttributes());
        $this->assertSame('张三',$user->getAttribute('name'));
        $this->assertSame(20,$user->getAttribute('age'));
        $this->assertSame(1,$user->getAttribute('status'));
    }

    /**
     * 测试实例保存(更新已存在记录)
     * @return void
     */
    public function testSaveUpdate(): void {
        $this->pdo->selectRows=[['id'=>1,'name'=>'张三','age'=>20,'status'=>1]];
        $user=User::find(1);
        $this->pdo->affectedRows=1;
        $user->name='李四';
        $this->assertTrue($user->save());
        $this->assertSame(
            'UPDATE `users` SET `name` = ?, `age` = ?, `status` = ?, `updated_at` = ? WHERE `id` = ?',
            $this->pdo->executed[1]
        );
    }

    /**
     * 测试实例删除
     * @return void
     */
    public function testDelete(): void {
        $this->pdo->selectRows=[['id'=>1,'name'=>'张三','age'=>20,'status'=>1]];
        $user=User::find(1);
        $this->pdo->affectedRows=1;
        $this->assertTrue($user->delete());
        $this->assertFalse($user->exists());
        $this->assertSame('DELETE FROM `users` WHERE `id` = ?',$this->pdo->executed[1]);
    }

    /**
     * 测试单字段值与字段列表
     * @return void
     */
    public function testValueAndPluck(): void {
        $this->pdo->selectRows=[['id'=>1,'name'=>'张三','age'=>20,'status'=>1]];
        $this->assertSame('张三',User::query()->where('id',1)->value('name'));
        $this->pdo->selectRows=[['name'=>'a'],['name'=>'b']];
        $this->assertSame(['a','b'],User::query()->pluck('name'));
    }

    /**
     * 测试表名由类名自动推导
     * @return void
     */
    public function testTableNameResolution(): void {
        $this->assertSame('users',User::tableName());
        $this->assertSame('system_info',SystemInfo::tableName());
    }

    /**
     * 测试创建记录自动填充时间戳
     * @return void
     */
    public function testCreateSetsTimestamps(): void {
        $this->pdo->affectedRows=1;
        $post=Post::create(['title'=>'标题','content'=>'内容','status'=>1]);
        $this->assertNotNull($post->created_at);
        $this->assertNotNull($post->updated_at);
        $this->assertStringContainsString('created_at',$this->pdo->executed[0]);
        $this->assertStringContainsString('updated_at',$this->pdo->executed[0]);
    }

    /**
     * 测试更新已存在记录刷新 updated_at
     * @return void
     */
    public function testSaveRefreshUpdatedAt(): void {
        $this->pdo->selectRows=[['id'=>1,'title'=>'标题','created_at'=>'2025-01-01 00:00:00','updated_at'=>'2025-01-01 00:00:00']];
        $post=Post::find(1);
        $this->pdo->affectedRows=1;
        $post->title='新标题';
        $post->save();
        $this->assertStringContainsString('updated_at',$this->pdo->executed[1]);
    }

    /**
     * 测试查询默认排除软删除记录
     * @return void
     */
    public function testSoftDeleteExcludesFromQuery(): void {
        $this->pdo->selectRows=[];
        Post::query()->get();
        $this->assertSame('SELECT * FROM `posts` WHERE `deleted_at` IS NULL',$this->pdo->executed[0]);
    }

    /**
     * 测试 withTrashed 包含软删除记录
     * @return void
     */
    public function testWithTrashed(): void {
        $this->pdo->selectRows=[];
        Post::query()->withTrashed()->get();
        $this->assertSame('SELECT * FROM `posts`',$this->pdo->executed[0]);
    }

    /**
     * 测试 onlyTrashed 仅查询软删除记录
     * @return void
     */
    public function testOnlyTrashed(): void {
        $this->pdo->selectRows=[];
        Post::query()->onlyTrashed()->get();
        $this->assertSame('SELECT * FROM `posts` WHERE `deleted_at` IS NOT NULL',$this->pdo->executed[0]);
    }

    /**
     * 测试 find 排除软删除记录
     * @return void
     */
    public function testFindExcludesTrashed(): void {
        $this->pdo->selectRows=[];
        $this->assertNull(Post::find(1));
        $this->assertSame(
            'SELECT * FROM `posts` WHERE `id` = ? AND `deleted_at` IS NULL LIMIT 1',
            $this->pdo->executed[0]
        );
    }

    /**
     * 测试实例删除为软删除
     * @return void
     */
    public function testInstanceDeleteIsSoft(): void {
        $this->pdo->selectRows=[['id'=>1,'title'=>'标题','deleted_at'=>null]];
        $post=Post::find(1);
        $this->pdo->affectedRows=1;
        $this->assertTrue($post->delete());
        $this->assertTrue($post->trashed());
        $this->assertSame(
            'UPDATE `posts` SET `deleted_at` = ? WHERE `id` = ?',
            $this->pdo->executed[1]
        );
    }

    /**
     * 测试恢复软删除记录
     * @return void
     */
    public function testRestore(): void {
        $this->pdo->selectRows=[['id'=>1,'title'=>'标题','deleted_at'=>'2025-01-01 00:00:00']];
        $post=Post::withTrashed()->find(1);
        $this->assertTrue($post->trashed());
        $this->pdo->affectedRows=1;
        $this->assertTrue($post->restore());
        $this->assertFalse($post->trashed());
        $this->assertSame(
            'UPDATE `posts` SET `deleted_at` = ? WHERE `id` = ?',
            $this->pdo->executed[1]
        );
    }

    /**
     * 测试构建器批量删除为软删除
     * @return void
     */
    public function testBuilderDeleteIsSoft(): void {
        $this->pdo->affectedRows=1;
        Post::query()->where('id',1)->delete();
        $this->assertSame(
            'UPDATE `posts` SET `deleted_at` = ? WHERE `id` = ? AND `deleted_at` IS NULL',
            $this->pdo->executed[0]
        );
    }

    /**
     * 测试强制物理删除
     * @return void
     */
    public function testForceDelete(): void {
        $this->pdo->selectRows=[['id'=>1,'title'=>'标题','deleted_at'=>null]];
        $post=Post::find(1);
        $this->pdo->affectedRows=1;
        $this->assertTrue($post->forceDelete());
        $this->assertFalse($post->exists());
        $this->assertSame('DELETE FROM `posts` WHERE `id` = ?',$this->pdo->executed[1]);
    }

}
