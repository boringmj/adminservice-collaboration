<?php

namespace app\index\controller;

use \Exception;
use base\Controller;
use AdminService\Log;
use AdminService\Autowire\AutowireProperty;

class Index extends Controller {

    /**
     * 通过属性注入Log代理对象
     * @var Log
     */
    #[AutowireProperty(Log::class,true)]
    private $log;

    /**
     * 关于控制器的自动参数注入
     *  - 可以在方法签名处声明需要的依赖,框架会自动注入对应的对象
     *  - 可以通过`AutowireProperty`或者`AutowireSetter`来标记需要注入的属性或方法
     *  - 请不要用任何标记来标记`public`属性的方法,否则可能出现意料之外的结果
     *  - 自动参数注入已经相对成熟,足够满足绝大部分情况
     * @throws Exception
     */
    public function index($name="World"): string {
        // 值得一说,如果你在路由中传入了name参数,那么这里的$name将会被覆盖
        $this->log->write($this->log::class.": Hello $name!");
        return "Hello $name!";
    }

}