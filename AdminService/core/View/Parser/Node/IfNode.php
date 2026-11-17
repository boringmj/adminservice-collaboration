<?php

namespace AdminService\View\Parser\Node;

use AdminService\View\Parser\Expression\ExpressionInterface;

/**
 * 条件节点
 *
 * @access public
 * @package AdminService\View\Parser\Node
 * @version 1.0.0
 */
final class IfNode {

    /**
     * 构造方法
     *
     * @access public
     * @param ExpressionInterface $condition 条件
     * @param array<int,mixed> $then 条件成立节点
     * @param array<int,mixed> $else 条件不成立节点
     */
    public function __construct(
        public ExpressionInterface $condition,
        public array $then,
        public array $else
    ) {
    }

}
