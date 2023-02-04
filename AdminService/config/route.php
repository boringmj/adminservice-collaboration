<?php

namespace AdminService\config;

// route 相关配置
return array(
    'default'=>array(
        'app'=>'index', // 默认应用
        'controller'=>'Index', // 默认控制器
        'method'=>'index' // 默认方法
    ),
    'params'=>array(
        'toget'=>array(
            'model'=>'list-value' // value, list, value-list, list-value (default: list-value)
        ),
        'rule'=>array(
            'app'=>'/^[a-z_][a-zA-Z0-9_\-]+$/', // 应用名规则
            'controller'=>'/^[A-Z_][a-zA-Z0-9_\-]+$/', // 控制器名规则
            'method'=>'/^[a-z_][a-zA-Z0-9_\-]+$/', // 方法名规则
            'get'=>'/^[a-zA-Z0-9_]+$/' // get参数名规则
        )
    )
);

?>