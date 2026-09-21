<?php

namespace Tests\Fixtures;

use base\Middleware;
use base\Request;

/**
 * 测试用中间件(不调用 $next, 中断后续)
 */
class BlockingMiddleware extends Middleware {

    /**
     * 处理器
     *
     * @access public
     * @param Request $request 请求对象
     * @param callable $next 下一个中间件
     * @return void
     */
    public function handle(Request $request,callable $next): void {
        MiddlewareLog::$calls[]='blocked';
        // 有意不调用 $next: 后续中间件与控制器均不执行
    }

}
