<?php

namespace AdminService\config;

use AdminService\Runtime;

// data 相关配置
return array(
    'path'=>Runtime::path().'/data', // 该目录需要可写权限, 如果目录不存在, 则请赋予其父目录可写权限, 系统会自动创建该目录
    'ext_name'=>'.data.json', // 文件扩展名
    'dir_mode'=>0755, // 自动创建的目录权限(Windows下无效)
    /**
     * 自动生成的文件名的周期(单位: 秒)
     *
     * 已弃用: 未指定文件名的自动缓存改用 cache.name_cycle;
     * 这里仅作为缺少 cache 配置时的兼容回退, 后续版本可能移除
     */
    'name_cycle'=>3600,
    'rule'=>array(
        'file'=>'/^[a-zA-Z0-9_\-]+$/', // 文件名规则(暂未生效)
        'key'=>'/^[a-zA-Z0-9_\-]+$/' // 数据的键名规则(暂未生效)
    )
);