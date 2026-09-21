<?php

namespace AdminService\config;

use AdminService\ArraySession;

// session 相关配置
// 会话服务在请求初始化时注册到容器, 用 App::get(\base\AbstractSession::class) 取用
return array(
    // 会话驱动(实现 base\AbstractSession)
    // - ArraySession: 内存驱动, 不落盘也不下发会话Cookie, 请求结束即消失(默认, 零配置安全)
    // - NativeSession: 原生会话, 会下发会话Cookie, 需要把下面的 start 打开(或在中间件里调用 init())
    'class'=>ArraySession::class,
    // 是否在请求初始化时开启会话(NativeSession 会在此时下发会话Cookie)
    // 需要按路由开启时, 可写一个请求中间件: App::get(\base\AbstractSession::class)->init()
    'start'=>false
);
