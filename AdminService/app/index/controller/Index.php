<?php

namespace app\index\controller;

use \Exception;
use base\Controller;
use AdminService\Log;

class Index extends Controller {

    /**
     * 依赖注入相比之前有了不少进步,但依旧不能保证兼容性,所以请谨慎使用
     * @throws Exception
     */
    public function index(Log $log,$name="World"): string {
        // 值得一说,如果你在路由中传入了name参数,那么这里的$name将会被覆盖
        $log->write("Hello $name!");
        return "Hello $name!";
    }

}