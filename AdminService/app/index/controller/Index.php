<?php

namespace app\index\controller;

use Exception;
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
     *  - 不建议使用任何标记来标记控制器的`public`属性的方法
     *  - 除非你知道其原理和且接受可能的副作用或其他未知风险
     *  - 已知风险: 部分标记可能导致方法被框架自动/重复调用
     * @throws Exception
     */
    public function index(string $name="World"): string {
        // 值得一说,如果你在路由中传入了name参数,那么这里的$name将会被覆盖
        $this->log->write($this->log::class.": Hello $name!");
        return "Hello $name!";
    }

}