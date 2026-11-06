<?php

namespace AdminService\Config;

use AdminService\Cache\EnvCache;

use function dirname;
use function function_exists;
use function getenv;
use function in_array;
use function is_file;
use function is_string;
use function opcache_is_script_cached;
use function strtolower;
use function trim;

/**
 * 进程级 `.env` 快照缓存
 *
 * - `env()` / `env_snapshot()` 共用同一份不可变快照
 * - 存在环境快照文件时直接读取, 不再读取与解析 `.env`; 快照值为权威值
 * - `ADMINSERVICE_ENV_SNAPSHOT=0` 关闭快照读取; 该开关必须在读取 `.env` 前可用
 * - 提供显式复位入口, 供测试、CLI 子进程或自定义入口重新读取环境文件
 */
final class EnvSnapshot {

    /**
     * 当前环境快照
     * @var Env|null
     */
    private static ?Env $snapshot=null;

    /**
     * 构造函数(禁止实例化)
     *
     * @access private
     */
    private function __construct() {
    }

    /**
     * 获取环境快照(首次调用时读取环境文件)
     *
     * @access public
     * @return Env
     */
    public static function get(): Env {
        if(self::$snapshot!==null)
            return self::$snapshot;
        $file=self::file();
        // 进程级开关优先: 关闭时不探测也不读取快照
        if(self::disabledBySwitch())
            return self::$snapshot=is_file($file)?Env::fromFile($file):new Env();
        // 测试/自定义环境文件且未指定快照路径时完全绕过快照, 避免写入项目 runtime
        if(self::usesCustomFile()&&!EnvCache::hasPathOverride())
            return self::$snapshot=is_file($file)?Env::fromFile($file):new Env();
        // 已进 opcache 时不再 stat; 未缓存或未启用时才检查文件
        $cache=EnvCache::path();
        $in_opcache=function_exists('opcache_is_script_cached')&&@opcache_is_script_cached($cache);
        if($in_opcache||is_file($cache)) {
            $cached=EnvCache::load($file,false);
            if($cached!==null)
                return self::$snapshot=$cached;
            if(!is_file($file)) {
                EnvCache::clear();
                return self::$snapshot=new Env();
            }
            // 快照损坏: 实时解析后覆盖
            $env=Env::fromFile($file);
            EnvCache::write($file,$env);
            return self::$snapshot=$env;
        }
        return self::$snapshot=is_file($file)?Env::fromFile($file):new Env();
    }

    /**
     * 环境文件路径
     *
     * - `ADMINSERVICE_TEST_ENV_FILE` 非空时使用它, 否则为项目根目录的 `.env`
     *
     * @access public
     * @return string
     */
    public static function file(): string {
        $file=getenv('ADMINSERVICE_TEST_ENV_FILE');
        if(is_string($file)&&trim($file)!=='')
            return $file;
        return dirname(__DIR__,3).'/.env';
    }

    /**
     * 是否使用测试/自定义环境文件
     *
     * @access public
     * @return bool
     */
    public static function usesCustomFile(): bool {
        $file=getenv('ADMINSERVICE_TEST_ENV_FILE');
        return is_string($file)&&trim($file)!=='';
    }

    /**
     * 进程级开关是否关闭环境快照
     *
     * - 读取 `ADMINSERVICE_ENV_SNAPSHOT`; `0` / `false` / `off` 关闭, 其余按文件存在判断
     * - 必须在读取 `.env` 之前可用, 因此不使用 `.env` 键
     *
     * @access public
     * @return bool
     */
    public static function disabledBySwitch(): bool {
        $value=getenv('ADMINSERVICE_ENV_SNAPSHOT');
        if(!is_string($value))
            return false;
        return in_array(strtolower(trim($value)),array('0','false','off'),true);
    }

    /**
     * 替换当前进程快照
     *
     * - 供构建环境快照后同步进程状态
     *
     * @access public
     * @param Env $env 新快照
     * @return void
     */
    public static function replace(Env $env): void {
        self::$snapshot=$env;
    }

    /**
     * 清除缓存, 下次 `get()` 重新读取
     *
     * @access public
     * @return void
     */
    public static function reset(): void {
        self::$snapshot=null;
    }

}
