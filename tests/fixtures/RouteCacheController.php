<?php

namespace Tests\Fixtures;

use base\Attribute\Middleware;
use base\Attribute\RouteParam;

/**
 * 路由缓存测试控制器
 */
#[Middleware(RouteCacheMiddleware::class)]
class RouteCacheController {

    /**
     * 普通动作
     *
     * @access public
     * @return string
     */
    public function index(): string {
        return 'cached-index';
    }

    /**
     * 带参数绑定的动作
     *
     * @access public
     * @param string $value 路径参数
     * @return string
     */
    public function show(#[RouteParam('id')] string $value): string {
        return 'cached-show:'.$value;
    }

}
