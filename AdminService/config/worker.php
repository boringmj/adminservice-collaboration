<?php

namespace AdminService\config;

use function AdminService\env;

// 常驻 worker 配置(可选; 需要 Swoole 扩展)
return array(
    'host'=>env('WORKER_HOST','127.0.0.1'), // 监听地址
    'port'=>(int)env('WORKER_PORT',9501), // 监听端口
    'workers'=>(int)env('WORKER_WORKERS',1), // 进程数
);
