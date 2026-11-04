<?php

namespace AdminService;

use AdminService\Config\Bootstrapper;
use base\Container as ContainerContract;
use base\Request;
use base\Response;
use base\Route;
use ReflectionException;

use function version_compare;

final class Main {

    /**
     * 应用(引导 + 生命周期宿主)
     * @var Application|null
     */
    private ?Application $application=null;

    /**
     * 获取应用(不存在则建立)
     *
     * - 请求级容器由它承载: 关停阶段或自定义入口需要请求级对象时用它取(`application()->requestContainer()`)
     *
     * @access public
     * @return Application
     */
    public function application(): Application {
        return $this->application??=new Application();
    }

    /**
     * 初始化
     *
     * @access public
     * @return self
     * @throws Exception
     * @throws \Exception
     */
    public function init(): self {
        // 判断PHP版本
        if(version_compare(PHP_VERSION,'8.2.0','<'))
            exit('无法兼容您的PHP版本('.PHP_VERSION.'),需要PHP8.2.0及以上版本');
        // 注册错误处理(响应出口: 正常退出时按请求准备好响应再发送)
        // 关停阶段门面已收回应用级容器, 故这里直接向 Application 取请求级容器(框架内部不走门面)
        Error::register(function(): void {
            $context=$this->application?->requestContext();
            $container=$context?->container()??($this->application===null?App::getInstance():$this->application->requestContainer());
            $response=$context?->response()??$container->get(Response::class);
            $request=$context?->request()??$container->get(Request::class);
            $response->prepare($request);
            try {
                $response->send();
            } finally {
                $this->application?->flushRequestMetadata();
                $this->application?->endRequest();
            }
        },false);
        // 配置、缓存、容器与数据库装配由 Bootstrapper 统一完成
        $bootstrapper=new Bootstrapper();
        $this->application=$bootstrapper->boot();
        // 注入容器到错误处理器: 错误 / 异常路径不再经静态门面(容器不可用时走内置错误出口)
        Error::setContainer($this->application->container());
        // 请求 / 响应 / 会话属**请求级**对象: 由 Application::handle() 每请求新建, 不再在引导期注册
        // 初始化完成
        Error::setInitialized(true);
        return $this;
    }

    /**
     * 开始运行
     *
     * - 请求中间件在路由匹配前执行: 未命中的请求(404/405)同样经过
     * - 中间件若不调用 `$next` 则中断后续(含路由匹配), 此时由中间件自行设置响应
     * - 路由协调器持有请求对象, 属请求级对象: 每请求强制新建, 不复用容器中的旧实例
     * - 路由表本身由应用级 `RouterFactory` 缓存, 只在**同一 Application/进程内**复用; 默认 HTTP 入口每请求新建 `Main`
     *
     * @access public
     * @return void
     * @throws Exception|ReflectionException
     */
    public function run(): void {
        // 由应用承载请求处理: 每请求一个请求级容器(fork/reset 与门面指针切换都在 Application::handle() 内)
        $this->application()->handle(function(ContainerContract $container): void {
            $middlewares=Pipeline::order(Pipeline::normalize($this->application()->config()->get('middlewares.request',array())));
            (new Pipeline($middlewares,$container->get(Request::class),$container))->then(function() use ($container): void {
                $container->fresh(Route::class)->run();
            });
        },true);
    }

}
