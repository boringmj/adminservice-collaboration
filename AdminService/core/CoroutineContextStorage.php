<?php

namespace AdminService;

use function class_exists;

/**
 * 协程执行流的请求上下文存储
 *
 * - Swoole / OpenSwoole 协程按协程 ID 隔离
 * - 没有协程扩展时回落到 Fiber/顺序存储，不要求安装 Swoole
 * - 不把协程 ID 放进全局单例对象；每个协程只读写自己的上下文
 */
final class CoroutineContextStorage implements ContextStorageInterface {

    /**
     * 协程 ID → 上下文
     * @var array<int,RequestContext>
     */
    private array $contexts=array();

    /**
     * 无协程 / Fiber 时的回落存储
     * @var FiberContextStorage
     */
    private FiberContextStorage $fallback;

    /**
     * 构造方法
     *
     * @access public
     * @param FiberContextStorage|null $fallback 回落存储
     */
    public function __construct(?FiberContextStorage $fallback=null) {
        $this->fallback=$fallback??new FiberContextStorage();
    }

    /**
     * 获取当前请求上下文
     *
     * @access public
     * @return RequestContext|null
     */
    public function current(): ?RequestContext {
        $cid=self::coroutineId();
        if($cid<=0)
            return $this->fallback->current();
        return $this->contexts[$cid]??null;
    }

    /**
     * 在指定上下文中执行回调
     *
     * @access public
     * @param RequestContext $context 请求上下文
     * @param callable(RequestContext):mixed $callback 回调
     * @return mixed
     */
    public function run(RequestContext $context,callable $callback): mixed {
        $cid=self::coroutineId();
        if($cid<=0)
            return $this->fallback->run($context,$callback);
        $previous=$this->contexts[$cid]??null;
        $this->contexts[$cid]=$context;
        try {
            return $callback($context);
        } finally {
            if($previous===null)
                unset($this->contexts[$cid]);
            else
                $this->contexts[$cid]=$previous;
        }
    }

    /**
     * 当前协程 ID(无协程返回 0)
     *
     * @access private
     * @return int
     */
    private static function coroutineId(): int {
        if(class_exists(\Swoole\Coroutine::class,false)) {
            $cid=(int)\Swoole\Coroutine::getCid();
            if($cid>0)
                return $cid;
        }
        if(class_exists(\OpenSwoole\Coroutine::class,false)) {
            $cid=(int)\OpenSwoole\Coroutine::getCid();
            if($cid>0)
                return $cid;
        }
        return 0;
    }

}
