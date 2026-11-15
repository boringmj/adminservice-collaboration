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
use AdminService\Response;
use AdminService\Worker\RequestFactory;
use AdminService\Worker\Worker;
use AdminService\Worker\WorkerResponse;
use base\AbstractInputProcessor;
use base\Controller;
use base\Database\Connection\PdoConnectionManager;
use base\Database\Connection\PdoConnectionPool;
use base\Database\Connection\PdoConnectionSession;
use base\Database\Db;
use base\Database\Sql\Compiler\MysqlCompiler;
use base\Database\Sql\Dialect\MysqlDialect;
use Fiber;
use ReflectionProperty;
use RuntimeException;
use Tests\Fixtures\FakePdo;
use Tests\Fixtures\StreamProbeController;

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
     * 测试: 流式生成器在请求收尾前执行, 能读到请求上下文
     *
     * @return void
     */
    public function testStreamProducerRunsBeforeTeardown(): void {
        $old_config=Config::repository();
        $old_app=App::hasInstance()?App::getInstance():null;
        $route=tempnam(sys_get_temp_dir(),'worker_stream_route_');
        file_put_contents($route,"<?php\nreturn function(\\AdminService\\Router\\Router \$router): void {\n"
            ."\$router->get('/stream-probe',array(\\Tests\\Fixtures\\StreamProbeController::class,'index'));\n"
            ."};\n");
        $configs=Config::all();
        $configs['route']['files']=array($route);
        $config=Config::new($configs);
        $app=(new Application(new Container(),$config))->init();
        $worker=new Worker($app);
        StreamProbeController::$calls=0;
        $context_during_stream=null;
        StreamProbeController::$probe=static function() use($app,&$context_during_stream): void {
            $context_during_stream=$app->requestContext();
        };
        try {
            $chunks='';
            $response=$worker->handle(RequestFactory::create('GET','/stream-probe'),static function(WorkerResponse $worker_response) use(&$chunks): void {
                $stream=$worker_response->stream();
                if($stream!==null)
                    $stream(static function(string $chunk) use(&$chunks): void {
                        $chunks.=$chunk;
                    });
            });
            $this->assertSame(200,$response->status());
            $this->assertSame(1,StreamProbeController::$calls);
            $this->assertNotNull($context_during_stream,'生成器执行时应仍保留请求上下文');
            $this->assertSame('probe',$chunks);
            $this->assertNull($app->requestContext());
        } finally {
            StreamProbeController::$probe=null;
            $worker->shutdown();
            if($old_app!==null)
                App::setInstance($old_app);
            if($old_config!==null)
                Config::setRepository($old_config);
            if(is_file($route))
                unlink($route);
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
     * 测试: 协程交错时异常请求使用自己的上下文, 不污染另一请求
     *
     * @return void
     */
    public function testInterleavedRequestErrorKeepsOwnContext(): void {
        $old_config=Config::repository();
        $old_app=App::hasInstance()?App::getInstance():null;
        $route=tempnam(sys_get_temp_dir(),'worker_interleave_route_');
        $log_dir=sys_get_temp_dir().'/adminservice_worker_interleave_'.uniqid();
        file_put_contents($route,"<?php\nreturn function(\\AdminService\\Router\\Router \$router): void {\n"
            ."\$router->get('/slow',function(): string { \\Fiber::suspend(); throw new \\RuntimeException('slow boom'); });\n"
            ."\$router->get('/fast',function(): string { \\Tests\\WorkerContextProbe::\$fast_response=\\AdminService\\App::get(\\AdminService\\Response::class); \\Fiber::suspend(); return 'fast-ok'; });\n"
            ."};\n");
        mkdir($log_dir);
        $configs=Config::all();
        $configs['route']['files']=array($route);
        $configs['log']['path']=$log_dir;
        $configs['log']['default_file']='worker_interleave';
        $configs['app']['debug']=false;
        $config=Config::new($configs);
        $app=(new Application(new Container(),$config))->init();
        $worker=new Worker($app);
        WorkerContextProbe::$fast_response=null;
        try {
            $slow=new Fiber(fn(): mixed => $worker->handle(RequestFactory::create('GET','/slow')));
            $fast=new Fiber(fn(): mixed => $worker->handle(RequestFactory::create('GET','/fast')));
            $slow->start();
            $fast->start();
            $slow->resume();
            $slow_response=$slow->getReturn();
            $fast->resume();
            $fast_response=$fast->getReturn();

            $this->assertInstanceOf(WorkerResponse::class,$slow_response);
            $this->assertInstanceOf(WorkerResponse::class,$fast_response);
            $this->assertSame(500,$slow_response->status());
            $this->assertStringContainsString('slow boom',$slow_response->body());
            $this->assertStringContainsString('发生错误',$slow_response->body());
            $this->assertSame(200,$fast_response->status());
            $this->assertSame('fast-ok',trim($fast_response->body()));
            $this->assertSame(200,WorkerContextProbe::$fast_response?->status());
            $this->assertSame('fast-ok',WorkerContextProbe::$fast_response?->rendered());
        } finally {
            $worker->shutdown();
            WorkerContextProbe::$fast_response=null;
            if($old_app!==null)
                App::setInstance($old_app);
            if($old_config!==null)
                Config::setRepository($old_config);
            if(is_file($route))
                unlink($route);
            if(is_file($log_dir.'/worker_interleave.log'))
                unlink($log_dir.'/worker_interleave.log');
            if(is_dir($log_dir))
                rmdir($log_dir);
        }
    }

    /**
     * 测试: 失败请求同样落盘容器元数据
     *
     * @return void
     */
    public function testFailedRequestFlushesContainerMetadata(): void {
        $old_config=Config::repository();
        $old_app=App::hasInstance()?App::getInstance():null;
        $route=tempnam(sys_get_temp_dir(),'worker_meta_route_');
        file_put_contents($route,"<?php\nreturn function(\\AdminService\\Router\\Router \$router): void {\n"
            ."\$router->get('/boom',function(): string { throw new \\RuntimeException('meta boom'); });\n"
            ."};\n");
        $log_dir=sys_get_temp_dir().'/adminservice_worker_meta_'.uniqid();
        mkdir($log_dir);
        $configs=Config::all();
        $configs['route']['files']=array($route);
        $configs['log']['path']=$log_dir;
        $configs['log']['default_file']='worker_meta';
        $configs['app']['debug']=false;
        $config=Config::new($configs);
        $app=(new Application(new Container(),$config))->init();
        $worker=new Worker($app);
        try {
            $metadata_property=new ReflectionProperty(Container::class,'metadata');
            $metadata_property->setAccessible(true);
            $metadata=$metadata_property->getValue($app->container());
            $dirty_property=new ReflectionProperty($metadata,'dirty');
            $dirty_property->setAccessible(true);
            $dirty_property->setValue($metadata,true);

            $response=$worker->handle(RequestFactory::create('GET','/boom'));
            $this->assertSame(500,$response->status());
            $this->assertFalse($dirty_property->getValue($metadata),'失败请求应落盘容器元数据');
        } finally {
            $worker->shutdown();
            if($old_app!==null)
                App::setInstance($old_app);
            if($old_config!==null)
                Config::setRepository($old_config);
            if(is_file($route))
                unlink($route);
            if(is_file($log_dir.'/worker_meta.log'))
                unlink($log_dir.'/worker_meta.log');
            if(is_dir($log_dir))
                rmdir($log_dir);
        }
    }

    /**
     * 测试: worker 请求经请求容器构建, 输入解析器可依赖注入
     *
     * @return void
     */
    public function testRequestIsBuiltWithContainer(): void {
        $old_config=Config::repository();
        $old_app=App::hasInstance()?App::getInstance():null;
        $route=tempnam(sys_get_temp_dir(),'worker_input_route_');
        file_put_contents($route,"<?php\nreturn function(\\AdminService\\Router\\Router \$router): void {\n"
            ."\$router->post('/input',array(\\Tests\\WorkerInputController::class,'read'));\n"
            ."};\n");
        $configs=Config::all();
        $configs['route']['files']=array($route);
        $configs['request']['default']['input']['application/json']=WorkerDependentInput::class;
        $configs['app']['debug']=false;
        $config=Config::new($configs);
        $app=(new Application(new Container(),$config))->init();
        $worker=new Worker($app);
        try {
            $response=$worker->handle(RequestFactory::create(
                'POST',
                '/input',
                array('content-type'=>'application/json'),
                array(),
                array(),
                array(),
                '{"x":1}'
            ));
            $this->assertSame(200,$response->status());
            $this->assertStringContainsString('injected:{"x":1}',trim($response->body()));
        } finally {
            $worker->shutdown();
            if($old_app!==null)
                App::setInstance($old_app);
            if($old_config!==null)
                Config::setRepository($old_config);
            if(is_file($route))
                unlink($route);
        }
    }

    /**
     * 测试: Swoole server 数组补齐 $_SERVER 风格大写键
     *
     * @return void
     */
    public function testNormalizeServerAddsUppercaseKeys(): void {
        $server=RequestFactory::normalizeServer(array(
            'remote_addr'=>'203.0.113.9',
            'remote_port'=>54321,
            'server_port'=>443,
            'server_protocol'=>'HTTP/2',
            'request_time'=>1700000000
        ));
        $this->assertSame('203.0.113.9',$server['REMOTE_ADDR']);
        $this->assertSame(54321,$server['REMOTE_PORT']);
        $this->assertSame(443,$server['SERVER_PORT']);
        $this->assertSame('HTTP/2',$server['SERVER_PROTOCOL']);
        $this->assertSame(1700000000,$server['REQUEST_TIME']);
        $this->assertSame('on',$server['HTTPS']);
        $this->assertSame('https',$server['REQUEST_SCHEME']);

        $existing=RequestFactory::normalizeServer(array(
            'remote_addr'=>'10.0.0.1',
            'REMOTE_ADDR'=>'127.0.0.1'
        ));
        $this->assertSame('127.0.0.1',$existing['REMOTE_ADDR'],'已有大写键不得被覆盖');
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
 * 协程交错用例的响应探针
 */
final class WorkerContextProbe {

    /**
     * fast 请求的响应对象
     * @var Response|null
     */
    public static ?Response $fast_response=null;

}

/**
 * 输入解析器依赖
 */
final class WorkerInputService {

    /**
     * 标记值
     *
     * @access public
     * @return string
     */
    public function value(): string {
        return 'injected';
    }

}

/**
 * 带构造依赖的输入解析器
 */
final class WorkerDependentInput extends AbstractInputProcessor {

    /**
     * 构造方法
     *
     * @access public
     * @param WorkerInputService $service 依赖
     */
    public function __construct(private WorkerInputService $service) {
    }

    /**
     * 解析输入
     *
     * @access protected
     * @param string $data 原始输入
     * @return array<string,string>
     */
    protected function handle(string $data): array {
        return array('token'=>$this->service->value().':'.$data);
    }

}

/**
 * 输入读取控制器
 */
final class WorkerInputController extends Controller {

    /**
     * 读取解析结果
     *
     * @access public
     * @return string
     */
    public function read(): string {
        return (string)$this->request->input('token');
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
