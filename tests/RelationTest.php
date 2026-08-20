<?php

namespace Tests;

use PHPUnit\Framework\TestCase;

use base\Database\Connection\PdoConnectionManager;
use base\Database\Connection\PdoConnectionPool;
use base\Database\Connection\PdoConnectionSession;
use base\Database\Db;
use base\Database\Sql\Dialect\MysqlDialect;
use base\Orm\ModelCollection;
use Tests\Fixtures\FakePdo;
use Tests\Fixtures\Post;
use Tests\Fixtures\Profile;
use Tests\Fixtures\User;

/**
 * 关系测试
 *
 * - 验证 hasMany/hasOne/belongsTo 查询、惰性加载与预加载
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
    }

    /**
     * 每个测试后清理
     * @return void
     */
    protected function tearDown(): void {
        User::setDb(null);
        Post::setDb(null);
        Profile::setDb(null);
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

}
