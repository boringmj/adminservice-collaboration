<?php

namespace AdminService\View\Parser\Expression;

/**
 * 条件表达式节点
 *
 * @access public
 * @package AdminService\View\Parser\Expression
 * @version 1.0.0
 */
interface ExpressionInterface {

    /**
     * 计算表达式
     *
     * @access public
     * @param array<string,mixed> $data 模板数据
     * @return mixed
     */
    public function evaluate(array $data): mixed;

}
