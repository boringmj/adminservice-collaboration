<?php

namespace AdminService;

use base\AbstractSession;
use base\Request;
use base\Response;
use base\Route;
use ReflectionException;

use function is_array;

final class Main {

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
        // 注册错误处理(响应出口: 正常退出时发送响应)
        Error::register(static function(): void {
            App::get(Response::class)->send(App::get(Request::class));
        },false);
        // 加载配置文件
        Config::load();
        // 加载函数库
        $this->loadFunction();
        // App初始化
        App::init();
        // 初始化请求与响应: 每请求新建实例(而非复位单例), 常驻模式下同样安全
        App::set(Request::class,App::new(Request::class));
        App::set(Response::class,App::new(Response::class));
        // 初始化Session
        $this->initSession();
        // 初始化完成
        Error::setInitialized(true);
        return $this;
    }

    /**
     * 初始化Session
     *
     * - 未启用时不注册: 会话服务不可从容器取用, 避免无谓的会话开启与Cookie下发
     *
     * @access private
     * @return void
     * @throws Exception
     * @throws ReflectionException
     */
    private function initSession(): void {
        if(!Config::get('session.enable',false))
            return;
        /** @var AbstractSession $session */
        $session=App::new(Config::get('session.class',NativeSession::class));
        $session->init();
        App::set(AbstractSession::class,$session);
    }

    /**
     * 加载函数库
     *
     * @access private
     * @return void
     */
    private function loadFunction(): void {
        $function_path=Config::get('function.path');
        $function_loader=Config::get('function.loader');
        if(is_array($function_loader)) {
            foreach($function_loader as $function) {
                $function_file=$function_path.'/'.$function.'.php';
                if(is_file($function_file))
                    include_once $function_file;
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
        $middlewares=(array)Config::get('middlewares.request',array());
        (new Pipeline($middlewares,App::get(Request::class)))->then(function(): void {
            App::make(Route::class,true)->run();
        });
    }

}
