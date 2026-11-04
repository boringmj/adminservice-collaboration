<?php

namespace AdminService;

use AdminService\Config\Env;
use AdminService\Config\EnvSnapshot;

/**
 * 读取 `.env` 配置项
 *
 * - 定位: 供 `config/*.php` **在配置装配期**取值用(那些文件里 `use function AdminService\env;` 后即可裸调)
 * - 与 `Config::get()` 的分工: `Config::get()` 读的是**生效配置**(会被 `.env` 的路径键覆盖);
 *   `env()` 读的是 `.env` 文件**本身**, 因此可以在配置文件里用
 * - 配置值只从环境文件读取, **不读进程环境变量**; 测试入口可用
 *   `ADMINSERVICE_TEST_ENV_FILE` 指定替代环境文件
 *
 *  1. **键名大小写敏感**: 不含点的键(约定的全大写蛇形, 如 `DB_HOST`)是"值来源";含点的键是"路径键",
 *     由 `Config\Repository` 按路径覆盖同名配置项 —— 解析口径见 `Env`
 *  2. **缺失就返回默认值**(默认 `null`), **不抛异常**。框架只管"有值就用、没有就回落",
 *     不替使用者判断"这个键该不该有" —— 也就是说**没有"必需键"这个概念**
 *  3. `.env` 的**语法错误抛 `ConfigException`**(由 `Env` 承担): 缺 `=`、空键、引号未闭合这些是内容
 *     正确性问题, 不能让一份写坏的 `.env` 静默生效
 *  4. 类型只转 bool / null, 数字保持字符串 —— 要数字请显式 `(int)env('DB_PORT', 3306)`
 *  5. 进程级缓存: 首次调用解析一次并留在 `EnvSnapshot` 中。`env()` 会在容器建立
 *     **之前**被调用, 没有别的可取之处; 这是本函数唯一的状态, 且只缓存"不可变快照";
 *     需要重新读取时调用 `env_reset()`
 *
 * @param string $key 键(**大小写敏感**; 如 `DB_HOST` / `app.debug`)
 * @param mixed $default 默认值(键缺失时返回它)
 * @return mixed
 */
function env(string $key,mixed $default=null): mixed {
    return env_snapshot()->get($key,$default);
}

/**
 * 取本次进程的环境文件快照(与 `env()` 用的是**同一份**, 不会二次解析)
 *
 * - 用途: `Config\Repository` 在**装载期**套"路径键覆盖"时要一次拿到整份 `.env`;
 *   `env()` 的缓存不可见, 因此提供本只读入口
 * - 返回的 `Env` 是**不可变快照**: 想绕开缓存请直接用 `Env::fromFile()` / `new Env()`
 *
 * @return Env
 */
function env_snapshot(): Env {
    return EnvSnapshot::get();
}

/**
 * 清除进程级 `.env` 快照缓存
 *
 * - 测试、CLI 子进程或自定义入口需要重新读取环境文件时调用
 * - 不影响已经构建完成的 `Config\Repository`; 需要重新装配配置时请显式替换配置仓储
 *
 * @return void
 */
function env_reset(): void {
    EnvSnapshot::reset();
}
