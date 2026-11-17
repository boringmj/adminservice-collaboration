<?php

namespace Tests;

use PHPUnit\Framework\TestCase;

use base\Database\Connection\PdoConnectionManager;
use base\Database\Connection\PdoConnectionPool;
use base\Database\Connection\PdoConnectionSession;
use base\Database\Db;
use base\Database\Sql\Dialect\MysqlDialect;
use base\Orm\Model;
use Fiber;
use Tests\Fixtures\FakePdo;
use Tests\Fixtures\Post;
use Tests\Fixtures\User;

/**
 * 模型数据库注入的作用域测试
 */
class ModelScopeTest extends TestCase {

    /**
     * 每个用例前清空注入缓存
     *
     * @return void
     */
    protected function setUp(): void {
        User::resetDbCache();
    }

    /**
     * 每个用例后清空注入缓存与作用域
     *
     * @return void
     */
    protected function tearDown(): void {
        User::resetDbCache();
        Db::setRequestScope(null);
    }

    /**
     * 测试: 请求作用域注入覆盖进程级注入, 释放后回落到进程级
     *
     * @return void
     */
    public function testScopedInjectionOverridesProcessInjection(): void {
        $global=$this->dbWithName('global');
        $scoped=$this->dbWithName('scoped');
        User::setDb($global);

        $seen=null;
        $fiber=new Fiber(function() use ($scoped,&$seen): void {
            Db::setRequestScope('request:test-1');
            User::setDb($scoped);
            $seen=User::query()->first()?->name;
            Model::releaseRequestScope('request:test-1');
            Db::setRequestScope(null);
        });
        $fiber->start();

        $this->assertSame('scoped',$seen);
        $this->assertSame('global',User::query()->first()?->name,'释放后应回落到进程级注入');
    }

    /**
     * 测试: 交错执行的请求作用域互不影响
     *
     * @return void
     */
    public function testScopedInjectionsAreIsolatedAcrossFibers(): void {
        $first=$this->dbWithName('first');
        $second=$this->dbWithName('second');
        $seen=array();
        $make=function(string $scope,Db $db,string $key) use (&$seen): Fiber {
            return new Fiber(function() use ($scope,$db,$key,&$seen): void {
                Db::setRequestScope($scope);
                User::setDb($db);
                Fiber::suspend();
                $seen[$key]=User::query()->first()?->name;
                Model::releaseRequestScope($scope);
                Db::setRequestScope(null);
            });
        };

        $one=$make('request:a',$first,'a');
        $two=$make('request:b',$second,'b');
        $one->start();
        $two->start();
        $one->resume();
        $two->resume();

        $this->assertSame(array('a'=>'first','b'=>'second'),$seen);
    }

    /**
     * 测试: 请求作用域内未注入时仍可见进程级注入
     *
     * @return void
     */
    public function testProcessInjectionRemainsVisibleInsideScope(): void {
        User::setDb($this->dbWithName('global'));

        $seen=null;
        $fiber=new Fiber(function() use (&$seen): void {
            Db::setRequestScope('request:test-3');
            $seen=User::query()->first()?->name;
            Db::setRequestScope(null);
        });
        $fiber->start();

        $this->assertSame('global',$seen);
    }

    /**
     * 测试: 请求级注入只影响调用的模型类
     *
     * @return void
     */
    public function testScopedInjectionOnlyAffectsTargetModel(): void {
        User::setDb($this->dbWithName('user-global'));
        Post::setDb($this->dbWithName('post-global'));

        $seen=null;
        $fiber=new Fiber(function() use (&$seen): void {
            Db::setRequestScope('request:test-4');
            User::setDb($this->dbWithName('user-scoped'));
            $seen=array(User::query()->first()?->name,Post::query()->first()?->title);
            Model::releaseRequestScope('request:test-4');
            Db::setRequestScope(null);
        });
        $fiber->start();

        $this->assertSame(array('user-scoped','post-global'),$seen);
        Post::setDb(null);
    }

    /**
     * 创建返回指定 name 的假数据库入口
     *
     * @access private
     * @param string $name 行数据中的 name
     * @return Db
     */
    private function dbWithName(string $name): Db {
        $pdo=new FakePdo();
        $pdo->selectRows=array(array('id'=>1,'name'=>$name,'title'=>$name,'age'=>20,'status'=>1));
        $factory=function() use ($pdo) {
            return new PdoConnectionSession($pdo,new MysqlDialect());
        };
        $manager=new PdoConnectionManager(new PdoConnectionPool($factory),$factory);
        return new Db($manager);
    }

}
