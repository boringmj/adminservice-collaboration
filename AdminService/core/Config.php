<?php

namespace AdminService;

use AdminService\Config\Loader;
use AdminService\Config\Repository;


/**
 * 配置门面
 *
 * - **门面**: 所有方法转发到"当前配置仓储实例"(`AdminService\Config\Repository`), 调用写法与旧版一致
 * - **无状态**: 唯一全局静态是指向当前仓储的指针(`set()` / `repository()`), 与 `App` 门面同构
 * - 实际装配(读 `config/*.php` + 合并 `.env`)在 `AdminService\Config\Loader`, 读取口径在 `Repository`;
 *   本类不再自己解析任何东西
 *
 * ## 与旧版(`AdminService\Config` 全静态实现)的行为对照
 *
 *  - `load()` / `set()` / `get()` / `all()` 的**签名与语义不变**, 调用点无需改动
 *  - `get()` / `all()` 在"尚未 load"时返回**默认值 / 空数组**, 不再抛
 *    `Error: Typed static property ... must not be accessed before initialization`(旧版 A1 缺陷, 结构性消除)
 *  - `new Config($configs)` **不再可用**(构造方法已私有): 旧版那个写法会顺手改掉全局配置, 语义意外(旧版 B4 缺陷);
 *    要整体替换配置请用 `set()`
 *  - `has()` 为新增(契约 `base\ConfigInterface` 同步补上)—— 口径与 `get()` 一致: 值为 `null` 视为不存在
 *
 * ## 为什么 `get()` 在未装配时返回默认值而不是抛异常
 *
 *  因为 `get()` 会在**引导期**被调用: `AdminService\Exception` 的构造方法里就有一处 `Config::get('app.debug')`。
 *  若那里抛异常, 就会把"真正的错误"替换成"二次故障"(同样的理由见 `AdminService\exception\ConfigException`)。
 *
 * @access public
 * @package AdminService
 * @version 2.0.0
 */
final class Config {

    /**
     * 当前配置仓储(唯一全局静态)
     * @var Repository|null
     */
    private static ?Repository $repository=null;

    /**
     * 构造方法(私有: 门面不可实例化)
     *
     * @access private
     */
    private function __construct() {
    }

    /**
     * 加载配置(读 `config/*.php`)并安装为当前仓储
     *
     * - 与旧版同语义: 整体替换当前配置; 重复调用会重新读盘
     * - **不再处理 `.env`**: `.env` 的值由配置文件里的 `env('KEY', $default)` 自己取
     *   (结构由配置文件声明;`.env` 的值由 `env()` 与路径键覆盖两条通道提供), 故本方法只负责 `config/*.php`
     *
     * @access public
     * @return void
     */
    public static function load(): void {
        $loader=new Loader(__DIR__.'/../config');
        // 把 `.env` 快照交给仓储: 它的"小写点分路径键"会覆盖同名配置项(见 Repository 的说明)
        self::$repository=new Repository($loader->load(),$loader->diagnostics(),env_snapshot());
    }

    /**
     * 设置配置(整体替换当前仓储)
     *
     * - 引导期与测试用: 传进来的数组成为**全部**配置, 不做深合并(与旧版语义一致)
     * - **不受 `.env` 路径键覆盖影响**: `set()` 的语义是"我就要这份配置"(引导期/测试),
     *   若还让部署侧的 `.env` 把它盖掉, 就会出现"明明 set 了却被改掉"的困惑
     * - 若当前已安装容器且容器里登记着配置实例, 会**同步换掉**容器里那一份 ——
     *   否则会出现"门面是新的、容器还是旧的"这种最难查的不一致
     *
     * @access public
     * @param array<string,mixed> $configs 全部配置
     * @return void
     */
    public static function set(array $configs): void {
        // 注意: **不带 `.env` 快照** —— `set()` 的语义是"我就要这份配置"(引导期/测试),
        // 若还让它被 `.env` 的路径键覆盖, 就会出现"我明明 set 了却被别处改掉"的困惑
        self::$repository=new Repository($configs);
        if(App::hasInstance())
            App::getInstance()->instance(\base\ConfigInterface::class,self::$repository);
    }

    /**
     * 取当前配置仓储(未装配时为 null)
     *
     * @access public
     * @return Repository|null
     */
    public static function repository(): ?Repository {
        return self::$repository;
    }

    /**
     * 是否已装配配置
     *
     * @access public
     * @return bool
     */
    public static function hasRepository(): bool {
        return self::$repository!==null;
    }

    /**
     * 获取全部配置
     *
     * @access public
     * @return array<string,mixed>
     */
    public static function all(): array {
        return self::$repository===null?array():self::$repository->all();
    }

    /**
     * 获取配置
     *
     * @access public
     * @param string $key 配置键(点分键)
     * @param mixed $default 默认值
     * @return mixed
     */
    public static function get(string $key,mixed $default=null): mixed {
        return self::$repository===null?$default:self::$repository->get($key,$default);
    }

    /**
     * 读取配置项(**只看配置文件**, 不看 `.env` 的路径键覆盖)
     *
     * - 排查用: "这个值到底是 `config/*.php` 里写的, 还是被 `.env` 覆盖了?"
     *
     * @access public
     * @param string $key 配置键(点分键)
     * @param mixed $default 默认值
     * @return mixed
     */
    public static function file(string $key,mixed $default=null): mixed {
        return self::$repository===null?$default:self::$repository->file($key,$default);
    }

    /**
     * 判断配置项是否存在
     *
     * @access public
     * @param string $key 配置键(点分键)
     * @return bool
     */
    public static function has(string $key): bool {
        return self::$repository!==null&&self::$repository->has($key);
    }

}
