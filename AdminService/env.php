<?php

use AdminService\Config\Env;

/**
 * 读取 `.env` 配置项(全局函数)
 *
 * - 定位: 供 `config/*.php` **在配置装配期**取值用, 因此必须能在任意命名空间里裸调 `env('KEY')`
 *   —— 所以本文件**不声明 namespace**(与 `common/` 下那批函数不同: 那批在 `AdminService\common`,
 *   调用方需要 `use function`; 而配置文件里到处写 `use function` 既啰嗦又容易漏)
 * - 与 `Config::get()` 的分工: `Config::get()` 读的是**合并后**的配置, 在配置文件里用不了(鸡生蛋);
 *   `env()` 读的是 `.env` 文件本身, 因此可以在配置文件里用
 * - 只认 `.env` 文件, **不读进程环境变量**: 刻意保持单一、可预测的来源(要放开再议)
 * - 取值口径(键名大小写不敏感、点分键按普通键、类型推断、引号、容错)全部由 `Env` 承载, 见该类的说明
 * - 进程级缓存: 首次调用解析一次并留在函数内 `static`。`env()` 会在容器建立**之前**被调用,
 *   没有别的可取之处; 这是本函数唯一的状态, 且只缓存"不可变快照", 不构成可变全局状态。
 *   需要绕开缓存时直接用实例 API(`new Env(...)` / `Env::fromFile(...)`)
 *
 * @param string $key 键(大小写不敏感; 点分键按普通键处理, 如 `app.debug`)
 * @param mixed $default 默认值(键不存在时返回)
 * @return mixed
 */
function env(string $key,mixed $default=null): mixed {
    static $env=null;
    if($env===null)
        // 本文件位于 `AdminService/` 下(比 `common/` 浅一层), 故只上溯一层到项目根
        $env=Env::fromFile(dirname(__DIR__).'/.env');
    return $env->get($key,$default);
}
