<?php

namespace AdminService\config;

// app 相关配置
return array(
    'path'=>__DIR__.'/../app', // app 目录
    'classes'=>array( // 需要初始化的类,这些类会自动绑定到容器中(这里就相当于一个别名,而且对象是懒加载的)
        'Router'=>\AdminService\Router::class,
        'View'=>\AdminService\View::class,
        'Request'=>\AdminService\Request::class,
        'File'=>\AdminService\File::class,
        'Exception'=>\AdminService\Exception::class,
        'Cookie'=>\AdminService\Cookie::class,
        'Config'=>\AdminService\Config::class,
        'Log'=>\AdminService\Log::class
    )
);

?>