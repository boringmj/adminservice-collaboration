<?php

namespace AdminService;

use AdminService\Config\Loader;
use AdminService\Config\Repository;
use AdminService\exception\ConfigException;


/**
 * 配置门面
 *
 * - **门面**: 所有方法转发到"当前配置仓储实例"(`AdminService\Config\Repository`)
 * - **无状态**: 唯一全局静态是指向当前仓储的指针(`set()` / `repository()`), 与 `App` 门面同构
 * - 实际装配(读 `config/*.php` + 合并 `.env`)在 `AdminService\Config\Loader`, 读取口径在 `Repository`;
 *   本类不再自己解析任何东西
 *
 * ## 对外接口
 *
 *  - `load()` / `set()` / `get()` / `all()` 的**签名与语义不变**, 调用点无需改动
 *  - `get()` / `all()` 在"尚未 load"时返回**默认值 / 空数组**(不抛)
 *  - `new Config($configs)` **不可用**(构造方法私有): 它会顺手改掉全局配置, 语义意外;要整体替换请用 `set()`
 *  - `has()` / `file()` / `setValue()` 见各自的方法注释
 *  - 生效值的层级见 `AdminService\Config\Repository`
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
     * - 整体替换当前配置; 重复调用会重新读盘
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
     * - 引导期与测试用: 传进来的数组成为**全部**配置, 不做深合并
     * - **这批值会被钉到"临时层"**(优先级最高): 于是"我刚 set 的值"不会被 `.env` 的路径键盖掉;
     *   而**其它键**的 `.env` 覆盖照旧生效(不是把整层 `.env` 拆掉, 只加一层)
     * - 若当前已安装容器且容器里登记着配置实例, 会**同步换掉**容器里那一份 ——
     *   否则会出现"门面是新的、容器还是旧的"这种最难查的不一致
     *
     * @access public
     * @param array<string,mixed> $configs 全部配置
     * @return void
     */
    public static function set(array $configs): void {
        // 保留 `.env` 快照(其它键的覆盖照旧), 同时把这一批值钉到临时层(优先级最高),
        // 于是"我刚 set 的值"不会被 `.env` 的路径键盖掉 —— 是"加一层", 不是把 `.env` 那层拆掉
        $repository=new Repository($configs,array(),env_snapshot());
        $repository->putAll($configs);
        self::$repository=$repository;
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
     * 写入一个**运行时临时值**(优先级最高, `.env` 的路径键也盖不掉)
     *
     * - 用途: 临时改一项, 不必重写整份配置, 也不会牵动 `.env` 的覆盖层
     * - 只允许写在**已存在的配置项**上;不存在的路径属代码写错, 直接抛(不让它变成"只活在代码里的配置项")
     * - ⚠ 它写在**应用级仓储实例**上: 常驻模式下会跨请求保留;需要请求级隔离请 `fork()` 请求级容器
     *   并在其仓储上写
     *
     * @access public
     * @param string $key 配置键(点分键)
     * @param mixed $value 值
     * @return void
     * @throws ConfigException 尚未装配配置, 或该配置项不存在
     */
    public static function setValue(string $key,mixed $value): void {
        if(self::$repository===null)
            throw new ConfigException('配置尚未装配, 无法写入临时值(请先调用 Config::load())',100906);
        self::$repository->put($key,$value);
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
