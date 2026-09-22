<?php

namespace AdminService\config;

use AdminService\View;
use AdminService\Error;
use AdminService\Route;
use AdminService\Cookie;
use AdminService\Response;
use AdminService\HttpRequest;

// app 相关配置
return array(
    // `.env` 里的 APP_DEBUG;不传第二个参数就是"必需键"(缺失即报错), 这里给了默认值表示可缺省
    'debug'=>env('APP_DEBUG',false), // 是否开启调试模式
    'param_cast'=>true, // 是否允许标量参数静默转换(对齐 PHP 弱类型,反射调用默认为严格类型,关闭后参数类型不匹配将抛出异常)
    'error_template'=>__DIR__.'/../view/error.html', // 错误页面模板路径
    'path'=>__DIR__.'/../app', // app 目录
    'classes'=>array( // 需要直接绑定到容器的真实类或接口,这些类会自动绑定到容器中(用于解决按需引入导致继承关系无法识别的问题,这些类会被强加载)
        // \base\View::class, // 这是一个例子
    ),
    'alias'=>array( // 别名,使用场景: 依赖注入,为父类(抽象类或接口)绑定一个固定的子类,或者为一个类绑定一个别名,一定程度上支持多层嵌套
        \base\View::class=>View::class,
        \base\Error::class=>Error::class,
        \base\Route::class=>Route::class,
        \base\Cookie::class=>Cookie::class,
        \base\Response::class=>Response::class,
        \base\Request::class=>HttpRequest::class,
        // 注意: `\base\ConfigInterface` 不在这里做别名 —— 它由引导期**登记实例**
        // (`Application::init()` 把配置仓储登记为契约实例), 这样按契约注入得到的是"真正那份配置",
        // 而不是一个转发到全局静态的替身
    )
);