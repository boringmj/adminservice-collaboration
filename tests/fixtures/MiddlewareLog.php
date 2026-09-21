<?php

namespace Tests\Fixtures;

/**
 * 测试用中间件调用记录
 */
final class MiddlewareLog {

    /**
     * 调用顺序
     * @var array<string>
     */
    public static array $calls=array();

    /**
     * 清空记录
     *
     * @access public
     * @return void
     */
    public static function clear(): void {
        self::$calls=array();
    }

}
