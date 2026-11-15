<?php

namespace Tests;

use PHPUnit\Framework\TestCase;

use AdminService\App;
use AdminService\Application;
use AdminService\Config;
use AdminService\Config\Repository;
use AdminService\Container;
use AdminService\Exception;
use AdminService\NativeSession;
use AdminService\Worker\RequestFactory;
use AdminService\Worker\Worker;
use base\Database\Connection\PdoConnectionManager;
use base\Database\Connection\PdoConnectionPool;
use base\Database\Connection\PdoConnectionSession;
use base\Database\Db;
use base\Database\Sql\Compiler\MysqlCompiler;
use base\Database\Sql\Dialect\MysqlDialect;
use ReflectionProperty;
use Tests\Fixtures\FakePdo;

use function file_get_contents;
use function file_put_contents;
use function is_dir;
use function is_file;
use function mkdir;
use function rmdir;
use function sys_get_temp_dir;
use function tempnam;
use function uniqid;
use function unlink;

/**
 * 常驻 worker 核心测试
 */
class WorkerTest extends TestCase {

    /**
     * 测试: 连续请求复用同一应用, 请求结束后不保留上下文
     *
     * @return void
     */
    public function testHandlesRequestsInSequence(): void {
        $old_config=Config::repository();
        $old_app=App::hasInstance()?App::getInstance():null;
        $config=Config::new(Config::all());
        $app=(new Application(new Container(),$config))->init();
        $worker=new Worker($app);
        try {
            $first=$worker->handle(RequestFactory::create('GET','/demo/index/index'));
            $this->assertSame(200,$first->status());
            $this->assertSame('Hello World!',trim($first->body()));
            $this->assertNull($app->requestContext(),'请求结束后不应保留请求上下文');

            $second=$worker->handle(RequestFactory::create('GET','/demo/index/index'));
            $this->assertSame(200,$second->status());
            $this->assertSame('Hello World!',trim($second->body()));
            $this->assertNull($app->requestContext());
        } finally {
            $worker->shutdown();
            if($old_app!==null)
                App::setInstance($old_app);
            if($old_config!==null)
                Config::setRepository($old_config);
        }
    }

    /**
     * 测试: 未命中路由仍返回框架响应
     *
     * @return void
     */
    public function testHandlesMissingRoute(): void {
        $old_config=Config::repository();
        $old_app=App::hasInstance()?App::getInstance():null;
        $config=Config::new(Config::all());
        $app=(new Application(new Container(),$config))->init();
        $worker=new Worker($app);
        try {
            $response=$worker->handle(RequestFactory::create('GET','/missing'));
            $this->assertSame(404,$response->status());
            $this->assertNotSame('',$response->body());
        } finally {
            $worker->shutdown();
            if($old_app!==null)
                App::setInstance($old_app);
            if($old_config!==null)
                Config::setRepository($old_config);
        }
    }

    /**
     * 测试: 请求异常返回框架错误页并写入应用日志
     *
     * @return void
     */
    public function testErrorRendersFrameworkPageAndWritesLog(): void {
        $old_config=Config::repository();
        $old_app=App::hasInstance()?App::getInstance():null;
        $route=tempnam(sys_get_temp_dir(),'worker_route_');
        $log_dir=sys_get_temp_dir().'/adminservice_worker_log_'.uniqid();
        file_put_contents($route,"<?php\nreturn function(\\AdminService\\Router\\Router \$router): void {\n"
            ."\$router->get('/boom',function(): string { throw new \\RuntimeException('worker boom'); });\n"
            ."};\n");
        mkdir($log_dir);
        $configs=Config::all();
        $configs['route']['files']=array($route);
        $configs['log']['path']=$log_dir;
        $configs['log']['default_file']='worker_test';
        $configs['app']['debug']=false;
        $config=Config::new($configs);
        $app=(new Application(new Container(),$config))->init();
        $worker=new Worker($app);
        try {
            $response=$worker->handle(RequestFactory::create('GET','/boom'));
            $headers=array_change_key_case($response->headers(),CASE_LOWER);
            $this->assertSame(500,$response->status());
            $this->assertStringContainsString('text/html',(string)($headers['content-type'][0]??''));
            $this->assertSame('true',(string)($headers['is-error'][0]??''));
            $this->assertStringContainsString('发生错误',$response->body());
            $this->assertStringContainsString('worker boom',$response->body());

            $log=(string)file_get_contents($log_dir.'/worker_test.log');
            $this->assertStringContainsString('worker boom',$log);
        } finally {
            $worker->shutdown();
            if($old_app!==null)
                App::setInstance($old_app);
            if($old_config!==null)
                Config::setRepository($old_config);
            if(is_file($route))
                unlink($route);
            if(is_file($log_dir.'/worker_test.log'))
                unlink($log_dir.'/worker_test.log');
            if(is_dir($log_dir))
                rmdir($log_dir);
        }
    }

    /**
     * 测试: 请求结束时回滚未提交事务, 事务会话不泄漏到下一请求
     *
     * @return void
     */
    public function testRequestBoundaryReleasesOpenTransaction(): void {
        $old_config=Config::repository();
        $old_app=App::hasInstance()?App::getInstance():null;
        $route=tempnam(sys_get_temp_dir(),'worker_db_route_');
        file_put_contents($route,"<?php\nreturn function(\\AdminService\\Router\\Router \$router): void {\n"
            ."\$router->get('/db/leak',array(\\Tests\\WorkerDbController::class,'leak'));\n"
            ."};\n");
        $pdo=new FakePdo();
        $factory=static function() use($pdo): PdoConnectionSession {
            return new PdoConnectionSession($pdo,new MysqlDialect());
        };
        $db=new Db(new PdoConnectionManager(new PdoConnectionPool($factory),$factory),new MysqlCompiler());
        $instances=new ReflectionProperty(Db::class,'instances');
        $instances->setAccessible(true);
        $configs=Config::all();
        $configs['route']['files']=array($route);
        $config=Config::new($configs);
        $app=(new Application(new Container(),$config))->init();
        $worker=new Worker($app);
        WorkerDbController::$db=$db;
        $instances->setValue(null,array('worker-test'=>$db));
        try {
            $response=$worker->handle(RequestFactory::create('GET','/db/leak'));
            $this->assertSame(200,$response->status());
            $this->assertSame(array('begin','rollback'),$pdo->transactionCalls,'请求边界未回滚未提交事务');
            $this->assertNull($db->getTransactionContext(),'事务会话不得泄漏到下一请求');
        } finally {
            $worker->shutdown();
            WorkerDbController::$db=null;
            $instances->setValue(null,array());
            if($old_app!==null)
                App::setInstance($old_app);
            if($old_config!==null)
                Config::setRepository($old_config);
            if(is_file($route))
                unlink($route);
        }
    }

    /**
     * 测试: worker 拒绝原生会话
     *
     * @return void
     */
    public function testRejectsNativeSession(): void {
        $old_config=Config::repository();
        $old_app=App::hasInstance()?App::getInstance():null;
        $configs=Config::all();
        $configs['session']['class']=NativeSession::class;
        $config=Config::new($configs);
        $app=(new Application(new Container(),$config))->init();
        $worker=new Worker($app);
        try {
            $this->expectException(Exception::class);
            $worker->boot();
        } finally {
            if($old_app!==null)
                App::setInstance($old_app);
            if($old_config!==null)
                Config::setRepository($old_config);
        }
    }

}

/**
 * 常驻 worker 数据库用例控制器
 *
 * - 在请求内开启事务但不提交, 用于验证请求边界的会话回收
 */
final class WorkerDbController {

    /**
     * 请求内使用的数据库入口(由用例注入)
     * @var Db|null
     */
    public static ?Db $db=null;

    /**
     * 开启事务并写入一行
     *
     * @access public
     * @return void
     */
    public function leak(): void {
        self::$db->beginTransaction();
        self::$db->raw('UPDATE users SET name = ?',array('leak'));
    }

}
