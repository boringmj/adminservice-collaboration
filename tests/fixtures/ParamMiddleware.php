<?php

namespace Tests\Fixtures;

use base\Middleware;
use base\Request;

/**
 * 测试用中间件(读取路径参数)
 */
class ParamMiddleware extends Middleware {

    /**
     * 处理器
     *
     * @access public
     * @param Request $request 请求对象
     * @param callable $next 下一个中间件
     * @return void
     */
    public function handle(Request $request,callable $next): void {
        // 路径参数位于属性区, 中间件可直接读取
        MiddlewareLog::$calls[]='param:'.$request->attribute('name');
        $next();
    }

}
