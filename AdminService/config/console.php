<?php

namespace AdminService\config;

use function AdminService\env;
use function dirname;

// console 相关配置
return array(
    // 无参数执行时的默认命令
    'default_command'=>'list',

    // 应用自定义命令类名, 需实现 \base\CommandInterface
    // 推荐放在 AdminService/app/{app}/command/, 命名空间 app\{app}\command
    'commands'=>array(
    ),

    // 根级快捷绑定: 简化路径 => 规范命令路径
    // 别名由命令自身 aliases() 提供, 不在这里重复配置
    'shortcuts'=>array(
        'start'=>'server start',
    ),

    // 内置 Web 服务默认配置
    'serve'=>array(
        'host'=>env('CLI_SERVE_HOST','localhost'),
        'port'=>(int)env('CLI_SERVE_PORT',8000),
        'docroot'=>dirname(__DIR__,2).'/public',
    ),
);
