<?php

namespace AdminService;

use base\ExecutionFlow;

use function round;
use function usleep;

/**
 * 协程环境查询与等待
 *
 * - 只认 Swoole / OpenSwoole 协程: 扩展已加载但不在协程内时按普通执行处理
 * - Fiber 不算协程: `id()` 为 0, `sleep()` 休眠当前执行流, 不让出事件循环
 *
 * @access public
 * @package AdminService
 * @version 1.0.0
 */
final class Coroutine {

    /**
     * 构造函数(禁止实例化)
     *
     * @access private
     */
    private function __construct() {
    }

    /**
     * 当前协程 ID
     *
     * @access public
     * @return int 不在协程内时为 0
     */
    public static function id(): int {
        list(,$cid)=self::current();
        return $cid;
    }

    /**
     * 是否处于协程内
     *
     * @access public
     * @return bool
     */
    public static function inside(): bool {
        return self::id()>0;
    }

    /**
     * 当前执行流标识
     *
     * @access public
     * @return string|null `swoole:2` / `openswoole:3`; 不在协程内时为 null
     */
    public static function key(): ?string {
        list($driver,$cid)=self::current();
        return $driver===null?null:$driver.':'.$cid;
    }

    /**
     * 等待指定秒数
     *
     * - 协程内让出事件循环; 其余环境休眠当前执行流
     *
     * @access public
     * @param int|float $seconds 秒数, 小于等于 0 时立即返回
     * @return void
     */
    public static function sleep(int|float $seconds): void {
        if($seconds<=0)
            return;
        list($driver)=self::current();
        if($driver==='swoole') {
            \Swoole\Coroutine\System::sleep($seconds);
            return;
        }
        if($driver==='openswoole') {
            \OpenSwoole\Coroutine\System::sleep($seconds);
            return;
        }
        usleep((int)round($seconds*1000000));
    }

    /**
     * 当前协程驱动与 ID
     *
     * @access private
     * @return array{0:string|null,1:int} 驱动名与协程 ID; 不在协程内为 `array(null,0)`
     */
    private static function current(): array {
        return ExecutionFlow::coroutine();
    }

}
