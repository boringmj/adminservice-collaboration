<?php

namespace AdminService\Worker;

use AdminService\Application;
use AdminService\ArraySession;
use AdminService\Config\Bootstrapper;
use AdminService\Exception;
use AdminService\HttpRequest;
use AdminService\NativeSession;
use AdminService\Pipeline;
use AdminService\RequestContext;
use AdminService\Response;
use base\Container as ContainerContract;
use base\Database\Db as BaseDb;
use base\Request;
use base\Route;
use Throwable;

use function error_log;
use function get_class;
use function is_a;
use function is_string;

/**
 * 常驻 worker
 *
 * - 启动一次应用, 顺序复用请求级作用域
 * - 不注册 `Error` 的关停出口; 每个请求结束时显式释放
 *
 * @access public
 * @package AdminService\Worker
 * @version 1.0.0
 */
final class Worker {

    /**
     * 应用
     * @var Application|null
     */
    private ?Application $application=null;

    /**
     * 引导器(未注入应用时使用)
     * @var Bootstrapper|null
     */
    private ?Bootstrapper $bootstrapper=null;

    /**
     * 是否已完成启动检查
     * @var bool
     */
    private bool $booted=false;

    /**
     * 构造方法
     *
     * @access public
     * @param Application|null $application 已启动的应用(null = 新建引导器)
     * @param Bootstrapper|null $bootstrapper 引导器(null = 新建)
     */
    public function __construct(?Application $application=null,?Bootstrapper $bootstrapper=null) {
        $this->application=$application;
        $this->bootstrapper=$bootstrapper;
    }

    /**
     * 启动应用
     *
     * @access public
     * @return Application
     * @throws Exception 会话驱动不可用
     */
    public function boot(): Application {
        if($this->application===null) {
            $this->bootstrapper??=new Bootstrapper();
            $this->application=$this->bootstrapper->boot();
        }
        if(!$this->booted) {
            $this->assertSessionDriver($this->application);
            $this->booted=true;
        }
        return $this->application;
    }

    /**
     * 处理一个请求
     *
     * @access public
     * @param WorkerRequest $request 请求
     * @return WorkerResponse
     */
    public function handle(WorkerRequest $request): WorkerResponse {
        $app=$this->boot();
        $context=null;
        try {
            $context=$app->handle(function(ContainerContract $container) use($app): RequestContext {
                $middlewares=Pipeline::order(Pipeline::normalize($app->config()->get('middlewares.request',array())));
                (new Pipeline($middlewares,$container->get(Request::class),$container))->then(
                    function() use($container): void {
                        $container->fresh(Route::class)->run();
                    }
                );
                return $container->get(RequestContext::class);
            },true,new HttpRequest($request->sources()));
            if(!$context instanceof RequestContext)
                throw new Exception('Worker request produced no context.');
            $core_request=$context->request();
            $core_response=$context->response();
            if(!$core_request instanceof Request||!$core_response instanceof Response)
                throw new Exception('Worker request produced no response.');
            return (new ResponseEmitter())->emit($core_response,$core_request);
        } catch(Throwable $e) {
            error_log('Worker request failed: '.get_class($e).': '.$e->getMessage());
            return $this->errorResponse($e,$app);
        } finally {
            if($context!==null) {
                $app->flushRequestMetadata($context->container());
                $app->endRequest($context);
            } else {
                // handle 抛错时仍可能有最近上下文, 显式释放
                $last=$app->requestContext();
                if($last!==null)
                    $app->endRequest($last);
            }
        }
    }

    /**
     * 当前应用
     *
     * @access public
     * @return Application|null
     */
    public function application(): ?Application {
        return $this->application;
    }

    /**
     * 释放应用与数据库缓存
     *
     * @access public
     * @return void
     */
    public function shutdown(): void {
        if($this->application===null)
            return;
        BaseDb::clearInstances();
        $this->application=null;
        $this->booted=false;
    }

    /**
     * 生成错误响应
     *
     * @access private
     * @param Throwable $e 异常
     * @param Application $app 应用
     * @return WorkerResponse
     */
    private function errorResponse(Throwable $e,Application $app): WorkerResponse {
        $debug=(bool)$app->config()->get('app.debug',false);
        $body=$debug
            ?get_class($e).': '.$e->getMessage()
            :'Internal Server Error';
        return new WorkerResponse(
            500,
            array('Content-Type'=>array('text/plain; charset=utf-8')),
            array(),
            $body
        );
    }

    /**
     * 检查会话驱动
     *
     * - 原生会话依赖 `$_SESSION` 与 SAPI 会话启动, 常驻模式不接受
     *
     * @access private
     * @param Application $app 应用
     * @return void
     * @throws Exception 会话驱动为原生会话
     */
    private function assertSessionDriver(Application $app): void {
        $class=$app->config()->get('session.class',ArraySession::class);
        if(is_string($class)&&is_a($class,NativeSession::class,true))
            throw new Exception('Worker does not support NativeSession; use ArraySession or a custom session driver.');
    }

}
