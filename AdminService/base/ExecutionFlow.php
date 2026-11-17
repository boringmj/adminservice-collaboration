<?php

namespace base;

use Fiber;

use function class_exists;
use function spl_object_id;

/**
 * 执行流标识
 *
 * - Fiber、Swoole 协程与 OpenSwoole 协程各自独立; 其余情况归入 `main`
 * - 只读取运行时状态, 不保存任何上下文
 *
 * @access public
 * @package base
 * @version 1.0.0
 */
final class ExecutionFlow {

    /**
     * 构造函数(禁止实例化)
     *
     * @access private
     */
    private function __construct() {
    }

    /**
     * 当前执行流标识
     *
     * @access public
     * @return string `fiber:N` / `swoole:N` / `openswoole:N` / `main`
     */
    public static function key(): string {
        if(($fiber=Fiber::getCurrent())!==null)
            return 'fiber:'.spl_object_id($fiber);
        list($driver,$cid)=self::coroutine();
        return $driver===null?'main':$driver.':'.$cid;
    }

    /**
     * 当前协程驱动与 ID
     *
     * - Fiber 不算协程; 扩展已加载但不在协程内时返回 `array(null,0)`
     *
     * @access public
     * @return array{0:string|null,1:int} 驱动名与协程 ID
     */
    public static function coroutine(): array {
        if(class_exists(\Swoole\Coroutine::class,false)&&($cid=\Swoole\Coroutine::getCid())>0)
            return array('swoole',(int)$cid);
        if(class_exists(\OpenSwoole\Coroutine::class,false)&&($cid=\OpenSwoole\Coroutine::getCid())>0)
            return array('openswoole',(int)$cid);
        return array(null,0);
    }

}
