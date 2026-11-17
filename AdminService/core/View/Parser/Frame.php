<?php

namespace AdminService\View\Parser;

use AdminService\View\Parser\Expression\ExpressionInterface;

/**
 * 模板解析栈帧
 *
 * @access public
 * @package AdminService\View\Parser
 * @version 1.0.0
 */
final class Frame {

    /**
     * 条件成立节点
     * @var array<int,mixed>
     */
    public array $then=array();

    /**
     * 条件不成立节点
     * @var array<int,mixed>
     */
    public array $else=array();

    /**
     * 是否已进入 else
     * @var bool
     */
    public bool $in_else=false;

    /**
     * 构造方法
     *
     * @access public
     * @param string $type if / foreach
     * @param ExpressionInterface|null $condition 条件
     * @param array<int,int|string>|null $source 集合路径
     * @param array<int,int|string>|null $key 键变量路径
     * @param array<int,int|string>|null $item 值变量路径
     */
    public function __construct(
        public string $type,
        public ?ExpressionInterface $condition=null,
        public ?array $source=null,
        public ?array $key=null,
        public ?array $item=null
    ) {
    }

    /**
     * 追加节点
     *
     * @access public
     * @param mixed $node 节点
     * @return void
     */
    public function add(mixed $node): void {
        if($this->in_else)
            $this->else[]=$node;
        else
            $this->then[]=$node;
    }

}
