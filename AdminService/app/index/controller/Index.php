<?php

namespace app\index\controller;

use Exception;
use base\Controller;
use AdminService\Log;
use AdminService\DynamicProxy;
use base\Attribute\AutowireMode;
use base\Attribute\AutowireProperty;
use base\Attribute\Route;
use base\Attribute\Method;
use base\Attribute\RouteGroup;

#[RouteGroup('/index')]
class Index extends Controller {

    /**
     * 通过属性注入Log代理对象
     * @var DynamicProxy<Log>
     */
    #[AutowireProperty(Log::class,mode:AutowireMode::Proxy)]
    private $log;

    /**
     * 关于控制器的自动参数注入
     *  - 可以在方法签名处声明需要的依赖,框架会自动注入对应的对象
     *  - 可以通过`AutowireProperty`或者`AutowireSetter`来标记需要注入的属性或方法
     *  - 不建议使用除`Route`标记外的其他标记来标记控制器的`public`属性的方法
     *  - 除非你知道其原理和且接受可能的副作用或其他未知风险
     *  - 已知风险: 部分标记可能导致方法被框架自动/重复调用
     *  - 可用`#[Route]`属性就近声明路由,框架会自动扫描控制器上的路由属性
     *  - 类上的`#[RouteGroup]`为该控制器下所有路由提供统一前缀与中间件
     *  - 可使用`#[RouteParam]`为控制器方法的形参显式绑定路由参数
     * @throws Exception
     */
    #[Route(Method::GET,'/{name?}',name:'index.index')]
    public function index(string $name="World"): string {
        // 路由传入的 `name` 会覆盖默认值
        $this->log->write($this->log::class.": Hello $name!");
        return "Hello $name!";
    }

}