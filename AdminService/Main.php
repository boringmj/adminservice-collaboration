<?php

namespace AdminService;

use AdminService\Route;
use AdminService\Exception;


final class Main {
    /**
     * 初始化
     * 
     * @return void
     */
    public function init() {
        // 调整环境
        // error_reporting(0);
        date_default_timezone_set('PRC');
        register_shutdown_function($this->end());
        // 路由
        $route=new Route();
        if($route=$route->load())
            return true;
        else
            throw new Exception("404 Not Found",404);
    }

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