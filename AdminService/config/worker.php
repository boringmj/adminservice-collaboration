<?php

namespace AdminService\config;

use function AdminService\env;
use function filter_var;

use const FILTER_VALIDATE_BOOLEAN;

// 常驻 worker 配置(可选; 需要 Swoole 扩展)
return array(
    'host'=>env('WORKER_HOST','127.0.0.1'), // 监听地址
    'port'=>(int)env('WORKER_PORT',9501), // 监听端口
    'workers'=>(int)env('WORKER_WORKERS',1), // 进程数
    // 引号 "false" 等字符串按布尔语义转换, 不当成真值
    'compression'=>filter_var(env('WORKER_COMPRESSION',false),FILTER_VALIDATE_BOOLEAN), // 是否启用 gzip 响应压缩(正文 1 KiB 起, 客户端需声明 Accept-Encoding: gzip)
    'compression_level'=>(int)env('WORKER_COMPRESSION_LEVEL',4), // 压缩级别 1-9
    // 默认值与请求上传上限(request.default.max_size, 100 MiB)对齐, 否则 worker 模式下大上传直接被拒
    'package_max_length'=>(int)env('WORKER_PACKAGE_MAX_LENGTH',104857600), // 单个请求最大字节数(<=0 使用 Swoole 默认值)
);
