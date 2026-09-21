<?php

namespace AdminService\config;

// route 相关配置
return array(
    'default'=>array(
        'app'=>'index', // 默认应用
        'controller'=>'Index', // 默认控制器
        'method'=>'index' // 默认方法
    ),
    // 显式路由
    'explicit'=>array(
        'file'=>__DIR__.'/../routes/web.php', // 集中式路由定义文件
        'attributes'=>array() // 需要扫描 #[Route] 属性的控制器类名, 留空则不扫描
    ),
    // 未命中显式路由时, 是否回落到 /app/controller/method 约定式解析
    'convention_fallback'=>true,
    'params'=>array(
        'to_get'=>array(
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