<?php

namespace AdminService\View\Parser\Expression;

/**
 * 字面量
 *
 * @access public
 * @package AdminService\View\Parser\Expression
 * @version 1.0.0
 */
final class Literal implements ExpressionInterface {

    /**
     * 构造方法
     *
     * @access public
     * @param mixed $value 字面量
     */
    public function __construct(public mixed $value) {
    }

    /**
     * 计算表达式
     *
     * @access public
     * @param array<string,mixed> $data 模板数据
     * @return mixed
     */
    public function evaluate(array $data): mixed {
        return $this->value;
    }

}
