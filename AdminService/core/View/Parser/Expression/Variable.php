<?php

namespace AdminService\View\Parser\Expression;

use AdminService\View\Runtime;

/**
 * 变量
 *
 * @access public
 * @package AdminService\View\Parser\Expression
 * @version 1.0.0
 */
final class Variable implements ExpressionInterface {

    /**
     * 构造方法
     *
     * @access public
     * @param array<int,int|string> $path 路径段
     */
    public function __construct(public array $path) {
    }

    /**
     * 计算表达式
     *
     * @access public
     * @param array<string,mixed> $data 模板数据
     * @return mixed
     */
    public function evaluate(array $data): mixed {
        return Runtime::get($data,$this->path);
    }

}
