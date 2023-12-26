<?php

namespace AdminService\config;

// app 相关配置
return array(
    'path'=>__DIR__.'/../app', // app 目录
    'classes'=>array( // 需要初始化的类,这些类会自动绑定到容器中(这些类必须可以直接实例化,不需要传入构造函数参数)
        'Router'=>\AdminService\Router::class,
        'View'=>\AdminService\View::class,
        'Request'=>\AdminService\Request::class,
        'File'=>\AdminService\File::class,
        'Exception'=>\AdminService\Exception::class,
        'Cookie'=>\AdminService\Cookie::class,
        'Config'=>\AdminService\Config::class,
        'Log'=>\AdminService\Log::class,
        'DynamicProxy'=>\AdminService\DynamicProxy::class
    )
);

?>