<?php

namespace Tests\Fixtures;

use base\Attribute\Middleware;

/**
 * 测试用控制器(控制器级中间件属性)
 *
 * - 类上两条声明按 `priority` 排序, 方法上一条
 */
#[Middleware(new LabelMiddleware('class_low'),priority:1)]
#[Middleware(new LabelMiddleware('class_high'),priority:10)]
class MiddlewaredController {

    /**
     * 处理器
     *
     * @access public
     * @return string
     */
    #[Middleware(new LabelMiddleware('method_low'),priority:1)]
    public function handle(): string {
        return 'mw-handled';
    }

    /**
     * 无任何控制器级中间件的处理器
     *
     * @access public
     * @return string
     */
    public function plain(): string {
        return 'mw-plain';
    }

}
