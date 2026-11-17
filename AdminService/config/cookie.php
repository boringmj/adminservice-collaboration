<?php

namespace AdminService\config;

// cookie 相关配置
return array(
    'prefix'=>'', // 前缀 default: ''
    'expire'=>3600, // 过期时间 default: 3600
    'path'=>'/', // 路径 default: ''
    'domain'=>'', // 域名 default: ''
    'secure'=>false, // 是否仅仅通过安全的 HTTPS 连接传给客户端 default: false(生产环境应设为 true)
    'httponly'=>true, // 是否禁止脚本访问 default: true
    'samesite'=>'Lax' // 跨站发送策略: Lax / Strict / None / ''(不设置) default: Lax
);