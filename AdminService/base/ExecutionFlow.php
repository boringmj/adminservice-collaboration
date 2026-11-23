<?php

namespace base;

use Fiber;
use WeakMap;

use function class_exists;
use function count;
use function explode;
use function method_exists;

/**
 * 执行流标识
 *
 * - Fiber、Swoole 协程与 OpenSwoole 协程各自独立; 其余情况归入 `main`
 * - Fiber 按弱引用表分配单调递增标识, 对象销毁后标识不复用
 * - 除 Fiber 标识表外不保存任何上下文
 *
 * @access public
 * @package base
 * @version 1.1.0
 */
final class ExecutionFlow {

    /**
     * Fiber 对象到执行流标识的弱引用表
     * @var WeakMap<Fiber,string>|null
     */
    private static ?WeakMap $fiber_ids=null;

    /**
     * Fiber 标识单调递增计数
     * @var int
     */
    private static int $fiber_seq=0;

    /**
     * Swoole 扩展是否已加载(null = 尚未检查)
     * @var bool|null
     */
    private static ?bool $swoole_loaded=null;

    /**
     * OpenSwoole 扩展是否已加载(null = 尚未检查)
     * @var bool|null
     */
    private static ?bool $openswoole_loaded=null;

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
        if(($fiber=Fiber::getCurrent())!==null) {
            if(self::$fiber_ids===null)
                self::$fiber_ids=new WeakMap();
            $id=self::$fiber_ids[$fiber]??null;
            if($id===null) {
                self::$fiber_seq++;
                $id='fiber:'.self::$fiber_seq;
                self::$fiber_ids[$fiber]=$id;
            }
            return $id;
        }
        // 驱动未加载时直接归入 main, 不再构造协程查询
        if(!self::driverLoaded('swoole')&&!self::driverLoaded('openswoole'))
            return 'main';
        list($driver,$cid)=self::coroutine();
        return $driver===null?'main':$driver.':'.$cid;
    }

    /**
     * 执行流标识是否仍存活
     *
     * - `main` 恒存活; Fiber 随对象存活, 对象销毁后不再存活
     * - Swoole / OpenSwoole 协程按协程存在性检查; 驱动未加载或检查方法不可用时无法判定, 按存活处理
     * - 未知格式按存活处理
     *
     * @access public
     * @param string $key 执行流标识
     * @return bool
     */
    public static function alive(string $key): bool {
        if($key==='main')
            return true;
        $parts=explode(':',$key,2);
        if(count($parts)!==2)
            return true;
        if($parts[0]==='fiber') {
            if(self::$fiber_ids===null)
                return false;
            foreach(self::$fiber_ids as $id)
                if($id===$key)
                    return true;
            return false;
        }
        if($parts[0]!=='swoole'&&$parts[0]!=='openswoole')
            return true;
        $class=$parts[0]==='swoole'?'\\Swoole\\Coroutine':'\\OpenSwoole\\Coroutine';
        if(!self::driverLoaded($parts[0]))
            return true;
        if(method_exists($class,'isExist'))
            return (bool)$class::isExist((int)$parts[1]);
        if(method_exists($class,'exists'))
            return (bool)$class::exists((int)$parts[1]);
        return true;
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
        if(self::driverLoaded('swoole')&&($cid=\Swoole\Coroutine::getCid())>0)
            return array('swoole',(int)$cid);
        if(self::driverLoaded('openswoole')&&($cid=\OpenSwoole\Coroutine::getCid())>0)
            return array('openswoole',(int)$cid);
        return array(null,0);
    }

    /**
     * 协程驱动扩展是否已加载
     *
     * - 扩展不能在运行期内加载或卸载, 结果按进程缓存
     *
     * @access private
     * @param string $driver `swoole` / `openswoole`
     * @return bool
     */
    private static function driverLoaded(string $driver): bool {
        if($driver==='swoole')
            return self::$swoole_loaded??(self::$swoole_loaded=class_exists(\Swoole\Coroutine::class,false));
        return self::$openswoole_loaded??(self::$openswoole_loaded=class_exists(\OpenSwoole\Coroutine::class,false));
    }

}
