<?php

namespace Tests\Fixtures;

use base\Middleware;
use base\Request;

/**
 * 测试用请求属性中间件
 *
 * - 向请求属性区写入与路由绑定目标同名的属性, 用于验证控制器形参只取自路由占位符
 */
class AttributeMiddleware extends Middleware {

    /**
     * 处理器
     *
     * @access public
     * @param Request $request 请求对象
     * @param callable $next 下一个中间件
     * @return void
     */
    public function handle(Request $request,callable $next): void {
        $request->setAttribute('first_name','middleware');
        $next();
    }

}
