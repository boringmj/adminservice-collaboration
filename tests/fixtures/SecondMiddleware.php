<?php

namespace Tests\Fixtures;

use base\Middleware;
use base\Request;

/**
 * 测试用中间件(记录进入与离开顺序)
 */
class SecondMiddleware extends Middleware {

    /**
     * 处理器
     *
     * @access public
     * @param Request $request 请求对象
     * @param callable $next 下一个中间件
     * @return void
     */
    public function handle(Request $request,callable $next): void {
        MiddlewareLog::$calls[]='second';
        $next();
        MiddlewareLog::$calls[]='second:after';
    }

}
