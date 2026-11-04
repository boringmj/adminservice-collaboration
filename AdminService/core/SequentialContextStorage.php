<?php

namespace AdminService;

use function array_key_last;
use function array_pop;

/**
 * 顺序执行流的请求上下文存储
 *
 * - 适用于 FPM、CGI、RoadRunner 等同一时刻只有一个活动请求的入口
 * - 使用栈支持嵌套 handle()
 */
final class SequentialContextStorage implements ContextStorageInterface {

    /**
     * 上下文栈
     * @var array<int,RequestContext>
     */
    private array $stack=array();

    /**
     * 获取当前请求上下文
     *
     * @access public
     * @return RequestContext|null
     */
    public function current(): ?RequestContext {
        if($this->stack===array())
            return null;
        return $this->stack[array_key_last($this->stack)];
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
        $this->stack[]=$context;
        try {
            return $callback($context);
        } finally {
            array_pop($this->stack);
        }
    }

}
