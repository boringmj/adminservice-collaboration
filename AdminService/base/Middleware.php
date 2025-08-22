<?php

namespace base;

abstract class Middleware {
    
    /**
     * 处理器
     * 
     * @access public
     * @param callable $next 下一个中间件
     * @return void
     */
    abstract public function handle(callable $next): void;

}