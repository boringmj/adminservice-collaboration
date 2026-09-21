<?php

namespace AdminService\config;

// route 相关配置
return array(
    // 路由文件或目录: 目录则加载其中全部 .php(按文件名排序), 可按应用拆分到多个文件
    'files'=>array(__DIR__.'/../routes'),
    // 属性路由: 在控制器方法上就近声明 #[Route], 见 AdminService\Router\AttributeScanner 的扫描规则
    'attributes'=>array(
        'scan'=>true, // 是否自动扫描 app.path 下 {app}/controller/*.php
        'classes'=>array() // 额外登记的控制器类名(不在 app 目录结构下时使用)
    )
);
