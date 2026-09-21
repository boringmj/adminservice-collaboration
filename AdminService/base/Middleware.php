<?php

namespace base;

abstract class Middleware {

    /**
     * 处理器
     *
     * - 参数按名注入, 形参名须与管道传入的键名一致
     *
     * @access public
     * @param Request $request 请求对象
     * @param callable $next 下一个中间件
     * @return void
     */
    abstract public function handle(Request $request,callable $next): void;

}