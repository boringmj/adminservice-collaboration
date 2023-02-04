<?php

namespace AdminService\config;

// log 相关配置
return array(
    'path'=>__DIR__.'/../log', // 该目录需要可写权限, 如果目录不存在, 则请赋予其父目录可写权限, 系统会自动创建该目录
    'ext_name'=>'.log', // 文件扩展名
    'dir_mode'=>0644, // 自动创建的目录权限(Windows下无效)
    'max_size'=>104857600, // 单个日志文件最大尺寸(单位: 字节),默认100M (default: 104857600)
    'rule'=>array(
        'file'=>'/^[a-zA-Z0-9_\-]+$/' // 文件名规则
    ),
    'row'=>'[{date}-{time}] {msg}', // 日志行格式,支持的变量有: {data}日期,{time}时间, {msg}日志内容
    'default_file'=>'{date}', // 默认日志文件名,支持的变量有: {data}日期, 系统不会检查文件名是否符合规则,请自行保证
);

?>