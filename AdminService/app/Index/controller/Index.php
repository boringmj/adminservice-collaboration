<?php

namespace app\Index\controller;

use bash\Controller;
use bash\Request;

class Index extends Controller {
    public function index() {
        print_r(Request::$request_params);
        // 输出所有请求参数的键值
        print_r(Request::keys());
        return "Hello World!";
    }

    public function test() {
        return "Hi ".$this->param(0)."!";
    }
}

?>