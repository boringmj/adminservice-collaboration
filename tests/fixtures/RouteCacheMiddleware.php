<?php

namespace Tests\Fixtures;

/**
 * 路由缓存测试中间件
 */
class RouteCacheMiddleware {

    /**
     * 处理请求
     *
     * @access public
     * @param mixed $request 请求
     * @param callable $next 下一层
     * @return void
     */
    public function handle(mixed $request,callable $next): void {
        $next();
    }

}
