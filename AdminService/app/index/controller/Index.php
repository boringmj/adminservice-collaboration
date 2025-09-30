<?php

namespace app\index\controller;

use \Exception;
use base\Controller;
use AdminService\Log;
use AdminService\AutowireProperty;

class Index extends Controller {


    /**
     * 通过属性注入Log代理对象
     * @var Log
     */
    #[AutowireProperty(Log::class,true)]
    private $log;

    /**
     * 自动参数注入已经相对成熟,足够满足绝大部分情况,
     * 你也可以在方法签名处声明需要的依赖,框架会自动注入对应的对象
     * @throws Exception
     */
    public function index(Log $log,$name="World"): string {
        // 值得一说,如果你在路由中传入了name参数,那么这里的$name将会被覆盖
        $this->log->write($this->log::class.": Hello $name!");
        $log->write($log::class.": Hi $name!");
        return "Hello $name!";
    }

}