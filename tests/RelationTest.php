<?php

namespace Tests;

use PHPUnit\Framework\TestCase;

use base\Database\Connection\PdoConnectionManager;
use base\Database\Connection\PdoConnectionPool;
use base\Database\Connection\PdoConnectionSession;
use base\Database\Db;
use base\Database\Sql\Dialect\MysqlDialect;
use base\Orm\Exception\OrmException;
use base\Orm\ModelCollection;
use Tests\Fixtures\FakePdo;
use Tests\Fixtures\Post;
use Tests\Fixtures\Profile;
use Tests\Fixtures\Role;
use Tests\Fixtures\User;

/**
 * 关系测试
 *
 * - 验证 hasMany/hasOne/belongsTo/belongsToMany 查询、惰性加载、预加载、关系写入与嵌套预加载
 */
class RelationTest extends TestCase {

    private FakePdo $pdo;

    /**
     * 每个测试前注入 FakePdo 数据库入口
     * @return void
     */
    protected function setUp(): void {
        $this->pdo=new FakePdo();
        User::setDb($this->createDb($this->pdo));
        Post::setDb($this->createDb($this->pdo));
        Profile::setDb($this->createDb($this->pdo));
        Role::setDb($this->createDb($this->pdo));
    }

    /**
     * 每个测试后清理
     * @return void
     */
    protected function tearDown(): void {
        User::setDb(null);
        Post::setDb(null);
        Profile::setDb(null);
        Role::setDb(null);
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
     * 测试 hasMany 链式查询
     * @return void
     */
    public function testHasManyChaining(): void {
        $this->pdo->selectRows=[];
        $user=User::newFromRow(['id'=>1,'name'=>'张三','age'=>20,'status'=>1]);
        $user->posts()->where('status',1)->get();
        $this->assertSame(
            'SELECT * FROM `posts` WHERE `user_id` = ? AND `status` = ? AND `deleted_at` IS NULL',
            $this->pdo->executed[0]
        );
        $this->assertSame([1=>1,2=>1],$this->pdo->bound);
    }

    /**
     * 测试 hasMany 惰性加载
     * @return void
     */
    public function testHasManyLazyLoad(): void {
        $this->pdo->selectRows=[['id'=>1,'title'=>'p1','user_id'=>1,'status'=>1,'deleted_at'=>null]];
        $user=User::newFromRow(['id'=>1,'name'=>'张三']);
        $posts=$user->posts;
        $this->assertInstanceOf(ModelCollection::class,$posts);
        $this->assertCount(1,$posts);
        $this->assertSame('p1',$posts->first()->title);
        $this->assertSame(
            'SELECT * FROM `posts` WHERE `user_id` = ? AND `deleted_at` IS NULL',
            $this->pdo->executed[0]
        );
    }

    /**
     * 测试 hasOne 惰性加载
     * @return void
     */
    public function testHasOneLazyLoad(): void {
        $this->pdo->selectRows=[['id'=>1,'user_id'=>1,'bio'=>'hi']];
        $user=User::newFromRow(['id'=>1,'name'=>'张三']);
        $profile=$user->profile;
        $this->assertInstanceOf(Profile::class,$profile);
        $this->assertSame('hi',$profile->bio);
        $this->assertSame('SELECT * FROM `profiles` WHERE `user_id` = ? LIMIT 1',$this->pdo->executed[0]);
    }

    /**
     * 测试 belongsTo 惰性加载
     * @return void
     */
    public function testBelongsToLazyLoad(): void {
        $this->pdo->selectRows=[['id'=>1,'name'=>'张三','age'=>20,'status'=>1]];
        $post=Post::newFromRow(['id'=>10,'user_id'=>1,'title'=>'t','deleted_at'=>null]);
        $user=$post->user;
        $this->assertInstanceOf(User::class,$user);
        $this->assertSame('张三',$user->name);
        $this->assertSame('SELECT * FROM `users` WHERE `id` = ? LIMIT 1',$this->pdo->executed[0]);
    }

    /**
     * 测试 hasMany 预加载(避免 N+1)
     * @return void
     */
    public function testEagerLoadHasMany(): void {
        $this->pdo->selectRowsQueue=[
            [['id'=>1,'name'=>'a','age'=>1,'status'=>1],['id'=>2,'name'=>'b','age'=>2,'status'=>1]],
            [['id'=>1,'title'=>'p1','user_id'=>1,'deleted_at'=>null],['id'=>2,'title'=>'p2','user_id'=>2,'deleted_at'=>null]],
        ];
        $users=User::query()->with('posts')->get();
        // 仅两次查询(父集合 + 关联批量), 无 N+1
        $this->assertCount(2,$this->pdo->executed);
        $this->assertSame('SELECT * FROM `users`',$this->pdo->executed[0]);
        $this->assertSame(
            'SELECT * FROM `posts` WHERE `user_id` IN (?, ?) AND `deleted_at` IS NULL',
            $this->pdo->executed[1]
        );
        $first=$users->first();
        $this->assertInstanceOf(ModelCollection::class,$first->posts);
        $this->assertSame('p1',$first->posts->first()->title);
        $this->assertSame('p2',$users->last()->posts->first()->title);
    }

    /**
     * 测试 belongsTo 预加载
     * @return void
     */
    public function testEagerLoadBelongsTo(): void {
        $this->pdo->selectRowsQueue=[
            [['id'=>10,'user_id'=>1,'title'=>'t','deleted_at'=>null]],
            [['id'=>1,'name'=>'a','age'=>1,'status'=>1]],
        ];
        $posts=Post::with('user')->get();
        $this->assertCount(2,$this->pdo->executed);
        $this->assertSame('SELECT * FROM `posts` WHERE `deleted_at` IS NULL',$this->pdo->executed[0]);
        $this->assertSame('SELECT * FROM `users` WHERE `id` IN (?)',$this->pdo->executed[1]);
        $this->assertSame('a',$posts->first()->user->name);
    }

    /**
     * 测试 belongsToMany 惰性加载(两步查询: 中间表 → 相关表 IN)
     * @return void
     */
    public function testBelongsToManyLazyLoad(): void {
        $this->pdo->selectRowsQueue=[
            [['role_id'=>1],['role_id'=>2]],
            [['id'=>1,'name'=>'admin'],['id'=>2,'name'=>'editor']],
        ];
        $user=User::newFromRow(['id'=>1,'name'=>'张三']);
        $roles=$user->roles;
        $this->assertInstanceOf(ModelCollection::class,$roles);
        $this->assertCount(2,$roles);
        $this->assertSame('admin',$roles->first()->name);
        $this->assertCount(2,$this->pdo->executed);
        $this->assertSame('SELECT `role_id` FROM `role_user` WHERE `user_id` = ?',$this->pdo->executed[0]);
        $this->assertSame('SELECT * FROM `roles` WHERE `id` IN (?, ?)',$this->pdo->executed[1]);
        $this->assertSame([1=>1,2=>1,3=>2],$this->pdo->bound);
    }

    /**
     * 测试 belongsToMany 无关联记录返回空集合(不查询相关表)
     * @return void
     */
    public function testBelongsToManyEmpty(): void {
        $this->pdo->selectRowsQueue=[
            array(), // 中间表无该用户的关联
        ];
        $user=User::newFromRow(['id'=>9,'name'=>'孤立用户']);
        $roles=$user->roles;
        $this->assertInstanceOf(ModelCollection::class,$roles);
        $this->assertCount(0,$roles);
        $this->assertCount(1,$this->pdo->executed);
    }

    /**
     * 测试 belongsToMany 链式查询(中间表过滤 + 额外条件)
     * @return void
     */
    public function testBelongsToManyChaining(): void {
        $this->pdo->selectRowsQueue=[
            [['role_id'=>1],['role_id'=>2]],
            [['id'=>1,'name'=>'admin'],['id'=>2,'name'=>'editor']],
        ];
        $user=User::newFromRow(['id'=>1,'name'=>'张三']);
        $roles=$user->roles()->where('status',1)->get();
        $this->assertCount(2,$roles);
        $this->assertSame(
            'SELECT * FROM `roles` WHERE `status` = ? AND `id` IN (?, ?)',
            $this->pdo->executed[1]
        );
    }

    /**
     * 测试 belongsToMany 预加载(父集合 + 中间表 + 相关表, 共三次查询)
     * @return void
     */
    public function testEagerLoadBelongsToMany(): void {
        $this->pdo->selectRowsQueue=[
            [['id'=>1,'name'=>'a','age'=>1,'status'=>1],['id'=>2,'name'=>'b','age'=>2,'status'=>2]],
            [['user_id'=>1,'role_id'=>1],['user_id'=>2,'role_id'=>2],['user_id'=>1,'role_id'=>3]],
            [['id'=>1,'name'=>'admin'],['id'=>2,'name'=>'editor'],['id'=>3,'name'=>'viewer']],
        ];
        $users=User::query()->with('roles')->get();
        $this->assertCount(3,$this->pdo->executed);
        $this->assertSame('SELECT * FROM `users`',$this->pdo->executed[0]);
        $this->assertSame(
            'SELECT `user_id`, `role_id` FROM `role_user` WHERE `user_id` IN (?, ?)',
            $this->pdo->executed[1]
        );
        $this->assertSame('SELECT * FROM `roles` WHERE `id` IN (?, ?, ?)',$this->pdo->executed[2]);
        $first=$users->first();
        $this->assertCount(2,$first->roles);
        $this->assertSame('admin',$first->roles->first()->name);
        $this->assertSame('viewer',$first->roles->last()->name);
        $this->assertCount(1,$users->last()->roles);
        $this->assertSame('editor',$users->last()->roles->first()->name);
    }

    /**
     * 测试 hasMany 关系写入(自动设置外键)
     * @return void
     */
    public function testRelationWriteHasManyCreate(): void {
        $this->pdo->affectedRows=1;
        $user=User::newFromRow(['id'=>1,'name'=>'张三']);
        $post=$user->posts()->create(['title'=>'新文章','content'=>'内容','status'=>1]);
        $this->assertInstanceOf(Post::class,$post);
        $this->assertSame(1,$post->getAttribute('user_id'));
        $this->assertSame('新文章',$post->title);
        $this->assertSame(
            'INSERT INTO `posts` (`title`, `content`, `status`, `user_id`, `created_at`, `updated_at`) VALUES (?, ?, ?, ?, ?, ?)',
            $this->pdo->executed[0]
        );
    }

    /**
     * 测试 hasOne 关系写入(自动设置外键)
     * @return void
     */
    public function testRelationWriteHasOneCreate(): void {
        $this->pdo->affectedRows=1;
        $user=User::newFromRow(['id'=>1,'name'=>'张三']);
        $profile=$user->profile()->create(['bio'=>'你好']);
        $this->assertInstanceOf(Profile::class,$profile);
        $this->assertSame(1,$profile->getAttribute('user_id'));
        $this->assertSame('你好',$profile->bio);
        $this->assertSame(
            'INSERT INTO `profiles` (`bio`, `user_id`, `created_at`, `updated_at`) VALUES (?, ?, ?, ?)',
            $this->pdo->executed[0]
        );
    }

    /**
     * 测试 belongsTo 关系写入不被支持
     * @return void
     */
    public function testBelongsToCreateThrows(): void {
        $this->expectException(OrmException::class);
        $post=Post::newFromRow(['id'=>10,'user_id'=>1,'title'=>'t','deleted_at'=>null]);
        $post->user()->create(['name'=>'x']);
    }

    /**
     * 测试嵌套预加载 with('posts.user')
     * @return void
     */
    public function testNestedEagerLoad(): void {
        $this->pdo->selectRowsQueue=[
            [['id'=>1,'name'=>'a','age'=>1,'status'=>1]],
            [['id'=>1,'title'=>'p1','user_id'=>1,'deleted_at'=>null]],
            [['id'=>1,'name'=>'a','age'=>1,'status'=>1]],
        ];
        $users=User::query()->with('posts.user')->get();
        $this->assertCount(3,$this->pdo->executed);
        $this->assertSame('SELECT * FROM `users`',$this->pdo->executed[0]);
        $this->assertSame(
            'SELECT * FROM `posts` WHERE `user_id` IN (?) AND `deleted_at` IS NULL',
            $this->pdo->executed[1]
        );
        $this->assertSame('SELECT * FROM `users` WHERE `id` IN (?)',$this->pdo->executed[2]);
        $post=$users->first()->posts->first();
        $this->assertInstanceOf(User::class,$post->user);
        $this->assertSame('a',$post->user->name);
    }

    /**
     * 测试 belongsToMany 关系写入(创建相关模型 + 写中间表)
     * @return void
     */
    public function testBelongsToManyCreate(): void {
        $this->pdo->affectedRows=1;
        $user=User::newFromRow(['id'=>1,'name'=>'张三']);
        $role=$user->roles()->create(['name'=>'staff','status'=>1]);
        $this->assertInstanceOf(Role::class,$role);
        // 两步: 先建角色(含时间戳), 再写中间表
        $this->assertCount(2,$this->pdo->executed);
        $this->assertSame(
            'INSERT INTO `roles` (`name`, `status`, `created_at`, `updated_at`) VALUES (?, ?, ?, ?)',
            $this->pdo->executed[0]
        );
        $this->assertSame(
            'INSERT INTO `role_user` (`user_id`, `role_id`) VALUES (?, ?)',
            $this->pdo->executed[1]
        );
    }

    /**
     * 测试 belongsToMany 不支持批量更新
     * @return void
     */
    public function testBelongsToManyUpdateThrows(): void {
        $this->expectException(OrmException::class);
        $user=User::newFromRow(['id'=>1,'name'=>'张三']);
        $user->roles()->update(array('name'=>'x'));
    }

    /**
     * 测试 belongsToMany 不支持批量删除
     * @return void
     */
    public function testBelongsToManyDeleteThrows(): void {
        $this->expectException(OrmException::class);
        $user=User::newFromRow(['id'=>1,'name'=>'张三']);
        $user->roles()->delete();
    }

    /**
     * 测试 belongsToMany 父模型未持久化时返回空集合且不发查询
     * @return void
     */
    public function testBelongsToManyUnpersistedParent(): void {
        $user=new User();
        $roles=$user->roles;
        $this->assertInstanceOf(ModelCollection::class,$roles);
        $this->assertCount(0,$roles);
        $this->assertCount(0,$this->pdo->executed);
    }

}
