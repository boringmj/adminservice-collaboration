<?php

namespace AdminService\config;

use AdminService\Runtime;

// cache 配置
return array(
    'path'=>Runtime::path().'/cache', // 缓存目录(可安全清理; 默认放在 runtime 下)
    'ext_name'=>'.data.json', // 自动生成缓存文件的后缀
    'dir_mode'=>0755, // 自动创建缓存目录权限(Windows下无效)
    'name_cycle'=>3600 // 自动生成缓存文件的周期(单位: 秒)
);
