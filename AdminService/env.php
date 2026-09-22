<?php

use AdminService\Config\Env;

/**
 * 读取 `.env` 配置项(全局函数)
 *
 * - 定位: 供 `config/*.php` **在配置装配期**取值用, 因此必须能在任意命名空间里裸调 `env('KEY')`
 *   —— 所以本文件**不声明 namespace**(与 `common/` 下那批函数不同: 那批在 `AdminService\common`,
 *   调用方需要 `use function`; 而配置文件里到处写 `use function` 既啰嗦又容易漏)
 * - 与 `Config::get()` 的分工: `Config::get()` 读的是**生效配置**(会被 `.env` 的路径键覆盖);
 *   `env()` 读的是 `.env` 文件**本身**, 因此可以在配置文件里用
 * - 只认 `.env` 文件, **不读进程环境变量**: 刻意保持单一、可预测的来源(要放开再议)
 *
 * ## 口径(按主流来, 2026-09-23 定)
 *
 *  1. **键名大小写敏感**、点号是普通字符 —— 见 `Env`
 *  2. **缺失就返回默认值**(默认 `null`), **不抛异常**。框架只管"有值就用、没有就回落",
 *     不替使用者判断"这个键该不该有" —— 也就是说**没有"必需键"这个概念**
 *  3. 与之配套: `.env` 的**语法错误也不抛**, 只记进 `Env::errors()`(由 `Env` 承担),
 *     要不要当回事交给部署前的 `tools/config_lint.php` 与使用者的调试日志
 *  4. 类型只转 bool / null, 数字保持字符串 —— 要数字请显式 `(int)env('DB_PORT', 3306)`
 *  5. 进程级缓存: 首次调用解析一次并留在函数内 `static`(见 `env_snapshot()`)。`env()` 会在容器建立
 *     **之前**被调用, 没有别的可取之处; 这是本函数唯一的状态, 且只缓存"不可变快照"
 *
 * @param string $key 键(**大小写敏感**; 点号是普通字符, 如 `DB_HOST` / `app.debug`)
 * @param mixed $default 默认值(键缺失时返回它)
 * @return mixed
 */
function env(string $key,mixed $default=null): mixed {
    return env_snapshot()->get($key,$default);
}

/**
 * 取本次进程的 `.env` 快照(与 `env()` 用的是**同一份**, 不会二次解析)
 *
 * - 为什么需要它: `Config\Repository` 做"路径键覆盖"时要能一次拿到整份 `.env`(按路径查表),
 *   而 `env()` 的缓存只藏在它自己的函数静态里, 外面拿不到 —— 于是把它暴露成一个只读入口
 * - 返回的 `Env` 是**不可变快照**: 想绕开缓存请直接用 `Env::fromFile()` / `new Env()`
 *
 * @return Env
 */
function env_snapshot(): Env {
    static $env=null;
    if($env===null) {
        // 本文件位于 `AdminService/` 下(比 `common/` 浅一层), 故只上溯一层到项目根
        $file=dirname(__DIR__).'/.env';
        // `.env` 不存在是正常情况(仓库里不入库), 按空处理
        $env=is_file($file)?Env::fromFile($file):new Env();
    }
    return $env;
}
