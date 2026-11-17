<?php

namespace AdminService\config;

use function AdminService\env;

// 常驻 worker 配置(可选; 需要 Swoole 扩展)
return array(
    'host'=>env('WORKER_HOST','127.0.0.1'), // 监听地址
    'port'=>(int)env('WORKER_PORT',9501), // 监听端口
    'workers'=>(int)env('WORKER_WORKERS',1), // 进程数
    'compression'=>env('WORKER_COMPRESSION',false), // 是否启用 gzip 响应压缩(正文 1 KiB 起, 客户端需声明 Accept-Encoding: gzip)
    'compression_level'=>(int)env('WORKER_COMPRESSION_LEVEL',4), // 压缩级别 1-9
    'package_max_length'=>(int)env('WORKER_PACKAGE_MAX_LENGTH',2097152), // 单个请求最大字节数(<=0 使用 Swoole 默认值)
);
