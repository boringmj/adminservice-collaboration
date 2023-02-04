<?php

namespace AdminService\config;

// cookie 相关配置
return array(
    'prefix'=>'', // 前缀 default: ''
    'expire'=>3600, // 过期时间 default: 3600
    'path'=>'/', // 路径 default: ''
    'domain'=>'', // 域名 default: ''
    'secure'=>false, // 是否仅仅通过安全的 HTTPS 连接传给客户端 default: false
    'httponly'=>false // 是否仅可通过 HTTP 协议访问 default: false
);

?>