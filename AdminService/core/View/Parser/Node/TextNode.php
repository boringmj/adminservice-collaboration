<?php

namespace AdminService\View\Parser\Node;

/**
 * 文本节点
 *
 * @access public
 * @package AdminService\View\Parser\Node
 * @version 1.0.0
 */
final class TextNode {

    /**
     * 构造方法
     *
     * @access public
     * @param string $text 文本
     */
    public function __construct(public string $text) {
    }

}
