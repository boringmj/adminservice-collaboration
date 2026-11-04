<?php

namespace AdminService\Config;

use function dirname;
use function getenv;
use function is_file;
use function is_string;
use function trim;

/**
 * 进程级 `.env` 快照缓存
 *
 * - `env()` / `env_snapshot()` 共用同一份不可变快照
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
        $file=getenv('ADMINSERVICE_TEST_ENV_FILE');
        if(!is_string($file)||trim($file)==='')
            // 本文件位于 `AdminService/core/Config/` 下(比 `common/` 深两层), 故上溯三层到项目根
            $file=dirname(__DIR__,3).'/.env';
        // 环境文件不存在是正常情况(仓库里不入库), 按空处理
        return self::$snapshot=is_file($file)?Env::fromFile($file):new Env();
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
