<?php

namespace AdminService;

use AdminService\Config\Env;

use function dirname;
use function is_string;
use function preg_match;
use function rtrim;
use function str_replace;
use function trim;
use function AdminService\env_reset;
use function AdminService\env_snapshot;

/**
 * Runtime 路径解析器
 *
 * - 职责: 在配置缓存之前确定可写运行时根目录, 供 `config/*.php` 与运行时组件使用
 * - 来源: `.env` 的 `RUNTIME_PATH`; 缺失、空值或非法值时回落到 `AdminService/runtime`
 * - 约束: 不依赖 `Config` / 容器 / 配置缓存; 进程内缓存默认解析结果
 * - 测试或自定义入口可用 `setPath()` / `reset()` 控制
 *
 * @access public
 * @package AdminService
 * @version 1.0.0
 */
final class Runtime {

    /**
     * 已解析的 runtime 根目录
     * @var string|null
     */
    private static ?string $path=null;

    /**
     * 构造函数(禁止实例化)
     *
     * @access private
     */
    private function __construct() {
    }

    /**
     * 获取 runtime 根目录
     *
     * @access public
     * @param Env|null $env 指定 env 快照(测试用); null 时使用进程级 `.env` 快照
     * @return string 已规范化的绝对或相对路径
     */
    public static function path(?Env $env=null): string {
        if($env!==null)
            return self::resolve($env);
        if(self::$path!==null)
            return self::$path;
        return self::$path=self::resolve(env_snapshot());
    }

    /**
     * 设置 runtime 根目录(测试或自定义入口使用)
     *
     * @access public
     * @param string $path runtime 根目录
     * @return void
     */
    public static function setPath(string $path): void {
        self::$path=self::normalize($path);
    }

    /**
     * 清除进程内缓存, 下次 `path()` 重新解析
     *
     * @access public
     * @return void
     */
    public static function reset(): void {
        self::$path=null;
    }

    /**
     * 重新读取环境快照并重算 runtime 路径
     *
     * - 常驻进程运行期修改 `.env` / `RUNTIME_PATH` 后使用
     * - 只负责 env 快照与路径缓存；已构建的 Config\Repository 仍需调用方重新装配
     *
     * @access public
     * @param Env|null $env 指定 env 快照(null = 重新读取进程级 `.env`)
     * @return string 新的 runtime 根目录
     */
    public static function reload(?Env $env=null): string {
        if($env===null)
            env_reset();
        self::$path=null;
        return self::path($env);
    }

    /**
     * 从 env 快照解析 runtime 根目录
     *
     * @access private
     * @param Env $env env 快照
     * @return string
     */
    private static function resolve(Env $env): string {
        $path=$env->get('RUNTIME_PATH','');
        if(!is_string($path)||trim($path)==='')
            $path=dirname(__DIR__).'/runtime';
        return self::normalize($path);
    }

    /**
     * 规范化路径: 统一分隔符并去掉末尾分隔符
     *
     * @access private
     * @param string $path 原始路径
     * @return string
     */
    private static function normalize(string $path): string {
        $default=str_replace('\\','/',dirname(__DIR__)).'/runtime';
        $path=trim(str_replace('\\','/',$path));
        // 文件系统根(含 Windows 驱动器根)不是独立的 runtime 目录, 按非法值回落默认目录;
        // 同时避免 rtrim('/') 把根目录变成空字符串.
        if($path===''||preg_match('/^[a-zA-Z]:\/*$/',$path)===1)
            return $default;
        $path=rtrim($path,'/');
        if($path==='')
            return $default;
        return $path;
    }

}
