<?php

namespace AdminService;

use Fiber;
use WeakMap;

use function class_exists;

/**
 * Fiber 执行流的请求上下文存储
 *
 * - 每个 Fiber 保存独立上下文；非 Fiber 调用回落顺序栈
 * - PHP 8.2 默认可用；不依赖 Swoole
 */
final class FiberContextStorage implements ContextStorageInterface {

    /**
     * Fiber 上下文表
     * @var WeakMap<Fiber,RequestContext>
     */
    private WeakMap $contexts;

    /**
     * 非 Fiber 执行流使用的顺序存储
     * @var SequentialContextStorage
     */
    private SequentialContextStorage $main;

    /**
     * 构造方法
     */
    public function __construct() {
        $this->contexts=new WeakMap();
        $this->main=new SequentialContextStorage();
    }

    /**
     * 获取当前请求上下文
     *
     * @access public
     * @return RequestContext|null
     */
    public function current(): ?RequestContext {
        $fiber=class_exists(Fiber::class)?Fiber::getCurrent():null;
        if($fiber===null)
            return $this->main->current();
        return $this->contexts[$fiber]??null;
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
        $fiber=class_exists(Fiber::class)?Fiber::getCurrent():null;
        if($fiber===null)
            return $this->main->run($context,$callback);
        $previous=$this->contexts[$fiber]??null;
        $this->contexts[$fiber]=$context;
        try {
            return $callback($context);
        } finally {
            if($previous===null)
                unset($this->contexts[$fiber]);
            else
                $this->contexts[$fiber]=$previous;
        }
    }

}
