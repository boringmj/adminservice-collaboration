<?php

namespace AdminService\config;

use AdminService\Log;
use AdminService\View;
use AdminService\File;
use AdminService\Route;
use AdminService\Config;
use AdminService\Cookie;
use AdminService\Exception;
use AdminService\Request;
use AdminService\DynamicProxy;

// app 相关配置
return array(
    'path'=>__DIR__.'/../app', // app 目录
    'classes'=>array( // 需要初始化的类,这些类会自动绑定到容器中(这些类必须可以直接实例化,不需要传入构造函数参数)
        'Route'=>Route::class,
        'View'=>View::class,
        'Request'=>Request::class,
        'File'=>File::class,
        'Exception'=>Exception::class,
        'Cookie'=>Cookie::class,
        'Config'=>Config::class,
        'Log'=>Log::class,
        'DynamicProxy'=>DynamicProxy::class
    ),
    'alias'=>array( // 别名,使用场景: 依赖注入,为父类(抽象类等)绑定一个固定的子类,或者为一个类绑定一个别名,没有完善多层嵌套
        \base\View::class=>View::class, // 依赖注入base\View时会自动实例化AdminService\View
        \base\Request::class=>Request::class, // 依赖注入base\Request时会自动实例化AdminService\Request
        \base\Cookie::class=>Cookie::class // 依赖注入base\Cookie时会自动实例化AdminService\Cookie
    )
);