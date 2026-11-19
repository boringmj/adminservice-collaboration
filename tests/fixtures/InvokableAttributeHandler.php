<?php

namespace Tests\Fixtures;

use base\Attribute\Middleware;

/**
 * 可调用对象处理器夹具(类级中间件声明)
 */
#[Middleware(new LabelMiddleware('invokable-class'))]
class InvokableAttributeHandler {

    /**
     * 处理器
     *
     * @access public
     * @return string
     */
    public function __invoke(): string {
        return 'invoked';
    }

}
