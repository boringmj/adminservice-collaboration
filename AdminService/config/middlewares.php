<?php

namespace AdminService\config;

// middlewares 相关配置
// 执行顺序(由外向内): 请求 → 分组(group) → 路由 → 控制器
// - 请求中间件在路由匹配前执行, 未命中的请求(404/405)同样经过, 适合跨域头、访问日志、统一错误体
// - 控制器中间件与控制器上的 `#[Middleware]` 属性合并, 该层内按 priority 排序(数值大者靠外)
// 每个中间件支持三种写法, 均可用含 priority 的条目声明层内优先级:
//   1. 类名(经容器实例化, 可依赖注入)  2. 已实例化对象  3. array('middleware'=>类名或实例,'priority'=>10)
return array(
    'request'=>array( // 请求中间件
    ),
    'controller'=>array( // 控制器中间件
    )
);
