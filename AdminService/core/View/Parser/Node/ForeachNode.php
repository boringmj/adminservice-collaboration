<?php

namespace AdminService\View\Parser\Node;

/**
 * 循环节点
 *
 * @access public
 * @package AdminService\View\Parser\Node
 * @version 1.0.0
 */
final class ForeachNode {

    /**
     * 构造方法
     *
     * @access public
     * @param array<int,int|string> $source 集合路径
     * @param array<int,int|string>|null $key 键变量路径
     * @param array<int,int|string> $item 值变量路径
     * @param array<int,mixed> $body 循环体节点
     */
    public function __construct(
        public array $source,
        public ?array $key,
        public array $item,
        public array $body
    ) {
    }

}
