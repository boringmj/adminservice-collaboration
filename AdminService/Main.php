<?php

namespace AdminService;

use bash\Request;
use AdminService\Route;
use AdminService\Exception;

final class Main {
    /**
     * 初始化
     * 
     * @access public
     * @return void
     */
    public function init() {
        // 调整环境
        // error_reporting(0);
        date_default_timezone_set('PRC');
        register_shutdown_function($this->end());
        $GLOBALS['AdminService']=array();
        // 加载配置文件
        $GLOBALS['AdminService']['config']=require_once __DIR__.'/config.php';
        new Config($GLOBALS['AdminService']['config']);
        // 路由
        $route=new Route();
        try{
            $route=$route->load();
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
                $Exception=new Exception($matches[1],-1);
                $Exception->echo();
            }
        };
    }
}

?>