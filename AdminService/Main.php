<?php

namespace AdminService;

use bash\Request;
use AdminService\Route;
use AdminService\Config;
use AdminService\Exception;

final class Main {
    
    /**
     * 初始化
     * 
     * @access public
     * @return void
     */
    public function init() {
        // 判断PHP版本
        if (version_compare(PHP_VERSION, '8.0.0', '<'))
            exit('无法兼容您的PHP版本('.PHP_VERSION.'),需要PHP8.0.0及以上版本');
        // 调整环境
        error_reporting(0);
        date_default_timezone_set('PRC');
        register_shutdown_function($this->end());
        $GLOBALS['AdminService']=array();
        // 加载配置文件
        $GLOBALS['AdminService']['config']=require_once __DIR__.'/config.php';
        (new Config())->set($GLOBALS['AdminService']['config']);
        // 路由
        $route=new Route();
        try{
            $route->load();
            Request::init();
            Request::requestExit($route->run());
        } catch(Exception $e) {
            Request::requestExit($e->getMessage());
        }
    }

    /**
     * 用于注册结束运行的事件,这里用于捕获异常
     * 
     * @access private
     * @return callable
     */
    private function end() {
        return function() {
            // 捕获致命错误
            $error=error_get_last();
            if (!empty($error)) {
                //通过正则表达式匹配出错误原因(in 前面的内容)
                preg_match('/^(.*?: .*?) in.*/',$error['message'],$matches);
                if(isset($matches[1]))
                    $error['message']=$matches[1];
                $Exception=new Exception($error['message'],-1);
                $Exception->echo();
            }
            // 如果没有异常则正常结束并输出内容
            Request::requestEcho();
            exit();
        };
    }
}

?>