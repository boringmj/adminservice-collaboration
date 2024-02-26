<?php

namespace AdminService\config;

// app 相关配置
return array(
    'path'=>__DIR__.'/../app', // app 目录
    'classes'=>array( // 需要初始化的类,这些类会自动绑定到容器中(这些类必须可以直接实例化,不需要传入构造函数参数)
        'Route'=>\AdminService\Route::class,
        'View'=>\AdminService\View::class,
        'Request'=>\AdminService\Request::class,
        'File'=>\AdminService\File::class,
        'Exception'=>\AdminService\Exception::class,
        'Cookie'=>\AdminService\Cookie::class,
        'Config'=>\AdminService\Config::class,
        'Log'=>\AdminService\Log::class,
        'DynamicProxy'=>\AdminService\DynamicProxy::class
    ),
    'alias'=>array( // 别名,使用场景: 依赖注入,为父类(抽象类等)绑定一个固定的子类,或者为一个类绑定一个别名,没有完善多层嵌套
        \base\View::class=>\AdminService\View::class, // 依赖注入base\View时会自动实例化AdminService\View
        \base\Request::class=>\AdminService\Request::class, // 依赖注入base\Request时会自动实例化AdminService\Request
        \base\Cookie::class=>\AdminService\Cookie::class, // 依赖注入base\Cookie时会自动实例化AdminService\Cookie
    )
);

?>