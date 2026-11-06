<?php

namespace AdminService\config;

use AdminService\Runtime;

use function AdminService\env;
use function dirname;
use function is_file;

// cache 配置
return array(
    // 基础路径
    'path'=>Runtime::path().'/cache', // 缓存根目录(可安全清理; 默认放在 runtime 下)
    'dir_mode'=>0755, // 自动创建缓存目录权限(Windows下无效)

    // 通用 KV 缓存
    'default_pool'=>'default', // 默认缓存池名(只允许字母、数字、下划线、短横线)
    'default_ttl'=>3600, // 默认过期时间(单位: 秒; <=0 表示永不过期)
    'gc_probability'=>1, // 写时触发自动 GC 的概率分子(<=0 关闭自动触发)
    'gc_divisor'=>100, // 写时触发自动 GC 的概率分母(默认 1/100)
    'gc_max_files'=>200, // 单次自动 GC 最多检查文件数(<=0 表示不限制)

    // 系统编译缓存(环境/配置/路由/容器已接入; 存在 .env 时配置缓存需环境快照放行)
    'compile'=>array(
        'enabled'=>env('CACHE_COMPILE',true), // 编译缓存总开关
        'mode'=>env('CACHE_COMPILE_MODE','auto'), // auto(debug下按 skip_on_debug 跳过或校验/生产零探测) | always | off
        'skip_on_debug'=>env('CACHE_COMPILE_SKIP_ON_DEBUG','config'), // debug+auto 跳过的缓存名(config/route/container, 逗号分隔); 配置缓存自身只读 .env
        'config'=>!is_file(dirname(__DIR__,2).'/.env'), // 存在 .env 时默认关闭; 生成环境快照后由 Bootstrapper 放行
        'route'=>true, // 路由表编译缓存
        'container'=>true // 容器 Autowire 与反射签名编译缓存
    )
);
