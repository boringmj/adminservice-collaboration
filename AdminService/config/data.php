<?php

namespace AdminService\config;

// data 相关配置
return array(
    'path'=>__DIR__.'/../data', // 该目录需要可写权限
    'ext_name'=>'.data.json', // 文件扩展名
    'dir_mode'=>0644, // 自动创建的目录权限(Windows下无效)
    'rule'=>array(
        'file'=>'/^[a-zA-Z0-9_\-]+$/', // 文件名规则(暂未生效)
        'key'=>'/^[a-zA-Z0-9_\-]+$/' // 数据的键名规则(暂未生效)
    )
);

?>