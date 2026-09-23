<?php

namespace AdminService;

use AdminService\Config\Loader;
use AdminService\Config\Repository;
use base\Database\Db as BaseDb;
use base\Request;
use base\Response;
use base\Route;
use AdminService\Database\DatabaseConfig;
use ReflectionException;

use function date_default_timezone_set;
use function error_reporting;
use function is_array;
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
        if(version_compare(PHP_VERSION,'8.1.0','<'))
            exit('无法兼容您的PHP版本('.PHP_VERSION.'),需要PHP8.1.0及以上版本');
        // 调整环境
        error_reporting(0);
        date_default_timezone_set('PRC');
        // 注册错误处理(响应出口: 正常退出时按请求准备好响应再发送)
        // 关停阶段门面已收回应用级容器, 故这里直接向 Application 取请求级容器(框架内部不走门面)
        Error::register(function(): void {
            $container=$this->application===null?App::getInstance():$this->application->requestContainer();
            $response=$container->get(Response::class);
            $response->prepare($container->get(Request::class));
            $response->send();
        },false);
        // 加载配置加载器 
        $loader=new Loader(__DIR__.'/config');
        $config=new Repository($loader->load(),env_snapshot());
        Config::setRepository($config);
        // 加载函数库
        $this->loadFunction($config);
        // App初始化(建应用级容器并按配置装配,同时把这份配置登记进容器,并安装到门面)
        $this->application=(new Application(null,$config))->init();
        // 安装数据库配置提供者: 数据库层(契约层)不读全局配置, 由应用层把配置能力交给它
        // 直接注入配置实例(而非让它回落门面): 引导期已定下这份配置, 之后只读
        BaseDb::setConfig(new DatabaseConfig($config));
        // 注入容器到错误处理器: 错误 / 异常路径不再经静态门面(容器不可用时走内置兜底)
        Error::setContainer($this->application->container());
        // 请求 / 响应 / 会话属**请求级**对象: 由 Application::handle() 每请求新建, 不再在引导期注册
        // 初始化完成
        Error::setInitialized(true);
        return $this;
    }

    /**
     * 加载函数库
     *
     * @access private
     * @param Repository $config 配置仓储(引导期运行在容器建立之前, 故由调用方直接传入)
     * @return void
     */
    private function loadFunction(Repository $config): void {
        $function_path=$config->get('function.path');
        $function_loader=$config->get('function.loader');
        if(is_array($function_loader)) {
            foreach($function_loader as $function) {
                // 直接加载(因性能原因不检查文件存在性)
                include_once $function_path.'/'.$function.'.php';
            }
        }
    }

    /**
     * 开始运行
     *
     * - 请求中间件在路由匹配前执行: 未命中的请求(404/405)同样经过
     * - 中间件若不调用 `$next` 则中断后续(含路由匹配), 此时由中间件自行设置响应
     * - 路由协调器持有请求对象, 属请求级对象: 每请求强制新建, 不复用容器中的旧实例
     *
     * @access public
     * @return void
     * @throws Exception|ReflectionException
     */
    public function run(): void {
        // 由应用承载请求处理: 每请求一个请求级容器(fork/reset 与门面指针切换都在 Application::handle() 内)
        $this->application()->handle(function(): void {
            $middlewares=Pipeline::order(Pipeline::normalize($this->application()->config()->get('middlewares.request',array())));
            (new Pipeline($middlewares,App::get(Request::class)))->then(function(): void {
                App::fresh(Route::class)->run();
            });
        });
    }

}
