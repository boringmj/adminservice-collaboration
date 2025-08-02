<?php

namespace AdminService;

use \ReflectionException;

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
        if(version_compare(PHP_VERSION,'8.0.0','<'))
            exit('无法兼容您的PHP版本('.PHP_VERSION.'),需要PHP8.0.0及以上版本');
        // 调整环境
        error_reporting(0);
        date_default_timezone_set('PRC');
        // 注册错误处理
        Error::register([Request::class,'requestEcho'],false);
        // 加载配置文件
        Config::load();
        // 加载函数库
        $this->loadFunction();
        // App初始化
        App::init();
        // 初始化请求
        Request::init();
        // 初始化完成
        Error::setInitialized(true);
        return $this;
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
     * @access public
     * @return void
     * @throws Exception|ReflectionException
     */
    public function run(): void {
        $route=App::get('Route');
        Request::requestExit($route->run());
    }

}