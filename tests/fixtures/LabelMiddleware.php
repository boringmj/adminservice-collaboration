<?php

namespace Tests\Fixtures;

use base\Middleware;
use base\Request;

/**
 * 测试用中间件(按标签记录调用顺序)
 */
class LabelMiddleware extends Middleware {

    /**
     * 标签
     * @var string
     */
    private string $label;

    /**
     * 构造方法
     *
     * @access public
     * @param string $label 标签
     */
    public function __construct(string $label) {
        $this->label=$label;
    }

    /**
     * 处理器
     *
     * @access public
     * @param Request $request 请求对象
     * @param callable $next 下一个中间件
     * @return void
     */
    public function handle(Request $request,callable $next): void {
        MiddlewareLog::$calls[]=$this->label;
        $next();
        MiddlewareLog::$calls[]=$this->label.':after';
    }

}
