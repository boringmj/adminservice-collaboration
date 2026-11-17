<?php

namespace AdminService\View\Parser\Node;

/**
 * 输出节点
 *
 * @access public
 * @package AdminService\View\Parser\Node
 * @version 1.0.0
 */
final class EchoNode {

    /**
     * 构造方法
     *
     * @access public
     * @param array<int,int|string> $path 变量路径
     * @param bool $escape 是否转义
     */
    public function __construct(public array $path,public bool $escape) {
    }

}
