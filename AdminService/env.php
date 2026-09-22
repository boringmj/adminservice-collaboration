<?php

use AdminService\Config\Env;
use AdminService\exception\ConfigException;

/**
 * 读取 `.env` 配置项(全局函数)
 *
 * - 定位: 供 `config/*.php` **在配置装配期**取值用, 因此必须能在任意命名空间里裸调 `env('KEY')`
 *   —— 所以本文件**不声明 namespace**(与 `common/` 下那批函数不同: 那批在 `AdminService\common`,
 *   调用方需要 `use function`; 而配置文件里到处写 `use function` 既啰嗦又容易漏)
 * - 与 `Config::get()` 的分工: `Config::get()` 读的是**合并后**的配置, 在配置文件里用不了(鸡生蛋);
 *   `env()` 读的是 `.env` 文件本身, 因此可以在配置文件里用
 * - 只认 `.env` 文件, **不读进程环境变量**: 刻意保持单一、可预测的来源(要放开再议)
 *
 * ## 口径(按主流来, 2026-09-23 定)
 *
 *  1. **键名大小写敏感**、点号是普通字符 —— 见 `Env`
 *  2. `env('KEY')` 与 `env('KEY', $default)` 的区别是**语义**的:
 *     - **不传第二个参数 = 必需键**: 缺失就抛 `ConfigException`(不在 `.env` 里悄悄给 `null`)
 *     - 传了默认值 = 可缺省: 缺失时回落默认值
 *     线上那次"`.env` 键名写错却静默失效"的事故, 靠这条就能在启动时炸出来
 *  3. `.env` **语法错误**(缺 `=`、引号未闭合等)同样**当场抛**: 主流实现(phpdotenv)也是这么做的;
 *     部署前还可以用 `tools/config_lint.php` 把它们提前查出来
 *  4. 类型只转 bool / null, 数字保持字符串 —— 要数字请显式 `(int)env('DB_PORT', 3306)`
 *  5. 进程级缓存: 首次调用解析一次并留在函数内 `static`。`env()` 会在容器建立**之前**被调用,
 *     没有别的可取之处; 这是本函数唯一的状态, 且只缓存"不可变快照", 不构成可变全局状态。
 *     需要绕开缓存时直接用实例 API(`new Env(...)` / `Env::fromFile(...)`)
 *
 * @param string $key 键(**大小写敏感**; 点号是普通字符, 如 `DB_HOST` / `app.debug`)
 * @param mixed $default 默认值(不传表示"必需", 缺失即抛)
 * @return mixed
 * @throws ConfigException `.env` 有语法错误, 或缺省了必需键
 */
function env(string $key,mixed $default=null): mixed {
    static $env=null;
    if($env===null) {
        // 本文件位于 `AdminService/` 下(比 `common/` 浅一层), 故只上溯一层到项目根
        $file=dirname(__DIR__).'/.env';
        // `.env` 不存在是正常情况(仓库里不入库), 不算错误
        $env=is_file($file)?Env::fromFile($file):new Env();
        if($env->errors()!==array())
            throw new ConfigException('`.env` 解析失败: '.implode(' | ',$env->errors()),100903);
    }
    if($env->has($key))
        return $env->get($key);
    if(func_num_args()<2)
        throw new ConfigException('`.env` 缺少必需键 "'.$key.'"(要在缺失时回落, 请显式传第二个参数)',100904);
    return $default;
}
