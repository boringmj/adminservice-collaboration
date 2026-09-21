<?php

namespace AdminService\config;

// middlewares 相关配置
// 执行顺序(由外向内): 请求 → 分组(group) → 路由 → 控制器
// - 请求中间件在路由匹配前执行, 未命中的请求(404/405)同样经过, 适合跨域头、访问日志、统一错误体
// - 控制器中间件为该层最低优先级, 与控制器上的 `#[Middleware]` 属性合并
// 每个中间件支持类名(经容器实例化, 可依赖注入)或已实例化对象
return array(
    'request'=>array( // 请求中间件
    ),
    'controller'=>array( // 控制器中间件
    )
);
