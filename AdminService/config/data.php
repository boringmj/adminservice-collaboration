<?php

namespace AdminService\config;

use AdminService\Runtime;

// data 相关配置
return array(
    'path'=>Runtime::path().'/data', // 该目录需要可写权限, 如果目录不存在, 则请赋予其父目录可写权限, 系统会自动创建该目录
    'ext_name'=>'.data.json', // 文件扩展名
    'dir_mode'=>0755 // 自动创建的目录权限(Windows下无效)
);