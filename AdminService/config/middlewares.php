<?php

namespace AdminService\config;

// middlewares 相关配置
// 执行顺序: global → 分组(group) → 路由 → controller, 依次由外向内包裹
// 每个中间件支持类名(经容器实例化, 可依赖注入)或已实例化对象
return array(
    'global'=>array( // 全局中间件
    ),
    'controller'=>array( // 控制器中间件
    )
);
