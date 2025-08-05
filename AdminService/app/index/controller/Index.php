<?php

namespace app\index\controller;

use \Exception;
use base\Controller;
use AdminService\Log;

class Index extends Controller {

    /**
     * 自动参数注入已经相对成熟,足够满足绝大部分情况
     * @throws Exception
     */
    public function index(Log $log,$name="World"): string {
        // 值得一说,如果你在路由中传入了name参数,那么这里的$name将会被覆盖
        $log->write("Hello $name!");
        return "Hello $name!";
    }

}