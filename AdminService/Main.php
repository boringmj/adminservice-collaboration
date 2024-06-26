<?php

namespace AdminService;

use \ReflectionException;

final class Main {

    /**
     * 初始化状态
     * 
     * @var bool
     */
    static private bool $init_finish;

    /**
     * 初始化
     *
     * @access public
     * @return self
     * @throws Exception
     * @throws \Exception
     */
    public function init(): self {
        // 初始化状态
        self::$init_finish=false;
        // 判断PHP版本
        if(version_compare(PHP_VERSION,'8.0.0','<'))
            exit('无法兼容您的PHP版本('.PHP_VERSION.'),需要PHP8.0.0及以上版本');
        // 调整环境
        error_reporting(0);
        date_default_timezone_set('PRC');
        register_shutdown_function($this->end());
        // 加载配置文件
        Config::load();
        // 加载函数库
        $this->loadFunction();
        // App初始化
        App::init();
        // 初始化请求
        Request::init();
        // 初始化完成
        self::$init_finish=true;
        return $this;
    }

    /**
     * 用于注册结束运行的事件,这里用于捕获异常
     * 
     * @access private
     * @return callable
     */
    private function end(): callable {
        return function() {
            // 捕获致命错误
            $error=error_get_last();
            if(!empty($error)) {
                //通过正则表达式匹配出错误原因(in 前面的内容)
                preg_match('/^(.*?: .*?) in.*/',$error['message'],$matches);
                if(isset($matches[1]))
                    $error['message']=$matches[1];
                // 取消输出缓冲
                while(ob_get_level()>0)
                    ob_end_clean();
                // 输出错误信息
                echo "<div>
                    <h1>发生错误或警告</h1>
                    <p>错误信息: {$error['message']}</p>
                    <p>错误文件: {$error['file']}</p>
                    <p>错误行数: {$error['line']}</p>
                </div>";
                // 记录日志
                if(self::$init_finish) {
                    try {
                        App::exec_class_function(Log::class,'write',array(
                            '发生错误或警告: {message} in {file} on line {line}',
                            array(
                                'message'=>$error['message'],
                                'file'=>$error['file'],
                                'line'=>$error['line']
                            )
                        ));
                    } catch(\Exception $e) {
                        echo "<br>日志记录失败: {$e->getMessage()}";
                    }
                }
                exit();
            }
            // 如果没有异常则正常结束并输出内容
            Request::requestEcho();
            exit();
        };
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
        try {
            // 路由
            $route=App::get('Route');
            Request::requestExit($route->run());
        } catch(Exception $e) {
            Request::requestExit($e->getMessage());
        }
    }

}