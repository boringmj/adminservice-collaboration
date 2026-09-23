<?php

namespace AdminService;

use AdminService\Config\Env;
use AdminService\Config\Repository;
use AdminService\exception\ConfigException;


/**
 * 配置门面
 *
 * @access public
 * @package AdminService
 * @version 2.0.0
 */
final class Config {

    /**
     * 当前配置存储实例
     * @var Repository|null
     */
    private static ?Repository $repository=null;

    /**
     * 禁用构造方法: 禁止实例化
     *
     * @access private
     */
    private function __construct() {
    }

    /**
     * 设置当前配置存储实例
     *
     * - 如果容器已经就绪,该方法会同步登记配置存储实例到容器
     *
     * @access public
     * @param Repository $repository 配置存储
     * @return void
     */
    public static function setRepository(Repository $repository): void {
        self::$repository=$repository;
        // 只按实现类名登记
        if(App::hasInstance())
            App::getInstance()->instance(Repository::class,self::$repository);
    }

    /**
     * 构建新的配置存储实例并返回
     *
     * @access public
     * @param array<string,mixed> $configs 配置树
     * @param bool $merge_env 是否让 env 的路径键参与合并
     * @param Env|null $env Env 实例(null = 进程级快照)
     * @return Repository 构建出来的实例
     */
    public static function new(array $configs,bool $merge_env=false,?Env $env=null): Repository {
        $repository=new Repository($configs,$env??env_snapshot(),$merge_env);
        return $repository;
    }

    /**
     * 取当前配置存储
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
     * - 未设置存储实例时回落默认值
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
     * 设置配置项生效值
     *
     * - 配置项必须实际存在
     * 
     * @access public
     * @param string $key 配置键(点分键)
     * @param mixed $value 值
     * @return void
     * @throws ConfigException 尚未装配配置, 或该配置项不存在
     */
    public static function setValue(string $key,mixed $value): void {
        if(self::$repository===null)
            throw new ConfigException('配置存储尚未就绪, 无法就地改配置项',100906);
        self::$repository->put($key,$value);
    }

    /**
     * 读取配置文件的原始配置项值
     *
     * - 未设置存储实例时回落默认值
     *
     * @access public
     * @param string $key 配置键(点分键)
     * @param mixed $default 默认值
     * @return mixed
     */
    public static function config_raw(string $key,mixed $default=null): mixed {
        return self::$repository===null?$default:self::$repository->config_raw($key,$default);
    }

    /**
     * 获取 env 配置项原始值
     *
     * - 未设置存储实例时回落默认值
     *
     * @access public
     * @param string $key 配置项名称(大小写敏感)
     * @param mixed $default 默认值
     * @return mixed
     */
    public static function env(string $key,mixed $default=null): mixed {
        return self::$repository===null?$default:self::$repository->env($key,$default);
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
