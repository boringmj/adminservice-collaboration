<?php

namespace app\index\controller;

use base\Controller;
use AdminService\Log;

class Index extends Controller {

    /**
     * 依赖注入相比之前有了不少进步,但依旧不能保证兼容性,所以请谨慎使用
     */
    public function index(Log $log,$name="World") {
        $log->write("Hello {$name}!");
        return "Hello {$name}!";
    }

}


?>