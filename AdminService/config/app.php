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
    'debug'=>false, // 是否开启调试模式
    'error_template'=>__DIR__.'/../view/error.html', // 错误页面模板路径
    'path'=>__DIR__.'/../app', // app 目录
    'classes'=>array( // 需要初始化的类,这些类会自动绑定到容器中(这些类必须可以直接实例化,不需要传入构造函数参数)
    ),
    'alias'=>array( // 别名,使用场景: 依赖注入,为父类(抽象类等)绑定一个固定的子类,或者为一个类绑定一个别名,一定程度上支持多层嵌套
        \base\View::class=>View::class,
        \base\Error::class=>Error::class,
        \base\Route::class=>Route::class,
        \base\Cookie::class=>Cookie::class,
        \base\Response::class=>Response::class,
        \base\Request::class=>HttpRequest::class,
    )
);