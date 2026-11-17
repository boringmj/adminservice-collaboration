<?php

namespace AdminService\View\Parser\Expression;

/**
 * 二元表达式
 *
 * @access public
 * @package AdminService\View\Parser\Expression
 * @version 1.0.0
 */
final class Binary implements ExpressionInterface {

    /**
     * 构造方法
     *
     * @access public
     * @param string $operator 运算符
     * @param ExpressionInterface $left 左操作数
     * @param ExpressionInterface $right 右操作数
     */
    public function __construct(
        public string $operator,
        public ExpressionInterface $left,
        public ExpressionInterface $right
    ) {
    }

    /**
     * 计算表达式
     *
     * @access public
     * @param array<string,mixed> $data 模板数据
     * @return mixed
     */
    public function evaluate(array $data): mixed {
        // 与编译后的 PHP 表达式一致: 逻辑运算短路, 不计算未选中分支
        if($this->operator==='&&')
            return (bool)$this->left->evaluate($data)&&(bool)$this->right->evaluate($data);
        if($this->operator==='||')
            return (bool)$this->left->evaluate($data)||(bool)$this->right->evaluate($data);
        $left=$this->left->evaluate($data);
        $right=$this->right->evaluate($data);
        return match($this->operator) {
            '==' => $left==$right,
            '!=' => $left!=$right,
            '<' => $left<$right,
            '>' => $left>$right,
            '<=' => $left<=$right,
            '>=' => $left>=$right,
            default => null
        };
    }

}
