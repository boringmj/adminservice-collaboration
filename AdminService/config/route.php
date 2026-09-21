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
        // 路由文件或目录: 目录则加载其中全部 .php(按文件名排序), 可按应用拆分到多个文件
        'files'=>array(__DIR__.'/../routes'),
        'attributes'=>array() // 需要扫描 #[Route] 属性的控制器类名, 留空则不扫描
    ),
    // 未命中显式路由时, 是否回落到 /app/controller/method 约定式解析
    // 关闭时未命中直接返回 404, 不泄漏目录结构
    'convention_fallback'=>false,
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