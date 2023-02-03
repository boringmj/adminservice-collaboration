<?php

namespace app\index\controller;

use base\Controller;
use AdminService\Log;

class Index extends Controller {

    /**
     * 目前依赖注入的问题非常多,特别是兼容性问题,所以暂时不推荐使用
     * 
     * 已经支持的依赖注入:
     * 1. 无参数的构造方法
     * 2. 无类型且存在默认值的变量
     */
    public function index(Log $log,$name="World") {
        $log->write("Hello {$name}!");
        return "Hello {$name}!";
    }

}

?>