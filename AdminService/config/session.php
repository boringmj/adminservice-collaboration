<?php

namespace AdminService\config;

use AdminService\NativeSession;

// session 相关配置
return array(
    'enable'=>false, // 是否启用Session, 启用后会话服务注册到容器, 可用 App::get(\base\AbstractSession::class) 取用
    'class'=>NativeSession::class // Session处理类
);
