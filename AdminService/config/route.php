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
        // 属性路由: 在控制器方法上就近声明 #[Route], 见 AdminService\Router\AttributeScanner 的扫描规则
        'attributes'=>array(
            'scan'=>true, // 是否自动扫描 app.path 下 {app}/controller/*.php
            'classes'=>array() // 额外登记的控制器类名(不在 app 目录下, 或文件名与类名不一致时使用)
        )
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