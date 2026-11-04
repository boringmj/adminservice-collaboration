<?php

namespace AdminService;

/**
 * 当前请求上下文存储
 *
 * - 负责把当前执行流映射到 RequestContext
 * - 顺序、Fiber、协程实现必须保持相同的 current()/run() 语义
 */
interface ContextStorageInterface {

    /**
     * 获取当前请求上下文
     *
     * @access public
     * @return RequestContext|null
     */
    public function current(): ?RequestContext;

    /**
     * 在指定上下文中执行回调
     *
     * @access public
     * @param RequestContext $context 请求上下文
     * @param callable(RequestContext):mixed $callback 回调
     * @return mixed
     */
    public function run(RequestContext $context,callable $callback): mixed;

}
