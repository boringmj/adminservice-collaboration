<?php

namespace AdminService\config;

use AdminService\Runtime;

use function AdminService\env;

// cache 配置
return array(
    // 既有项(兼容 File 自动缓存与基础路径)
    'path'=>Runtime::path().'/cache', // 缓存根目录(可安全清理; 默认放在 runtime 下)
    'ext_name'=>'.data.json', // File 自动生成缓存文件的后缀
    'dir_mode'=>0755, // 自动创建缓存目录权限(Windows下无效)
    'name_cycle'=>3600, // File 自动生成缓存文件的周期(单位: 秒)

    // 通用 KV 缓存
    'default_pool'=>'default', // 默认缓存池名(只允许字母、数字、下划线、短横线)
    'default_ttl'=>3600, // 默认过期时间(单位: 秒; <=0 表示永不过期)
    'gc_probability'=>1, // 写时触发自动 GC 的概率分子(<=0 关闭自动触发)
    'gc_divisor'=>100, // 写时触发自动 GC 的概率分母(默认 1/100)
    'gc_max_files'=>200, // 单次自动 GC 最多检查文件数(<=0 表示不限制)

    // 系统编译缓存(路由已接入; 配置/容器待接入)
    'compile'=>array(
        'enabled'=>env('CACHE_COMPILE',true), // 编译缓存总开关
        'mode'=>env('CACHE_COMPILE_MODE','auto'), // auto(debug下校验指纹/生产零探测) | always | off
        'config'=>true, // 配置与引导预检编译缓存
        'route'=>true, // 路由表编译缓存
        'container'=>true // 容器 Autowire 与反射签名编译缓存
    )
);
