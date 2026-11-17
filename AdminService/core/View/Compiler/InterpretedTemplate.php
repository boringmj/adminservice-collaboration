<?php

namespace AdminService\View\Compiler;

use AdminService\View\Parser\Node\EchoNode;
use AdminService\View\Parser\Node\ForeachNode;
use AdminService\View\Parser\Node\IfNode;
use AdminService\View\Parser\Node\TextNode;
use AdminService\View\Runtime;
use base\View\CompiledTemplateInterface;

/**
 * AST 解释器产物
 *
 * - 用于字符串模板与缓存不可用时的回落路径
 *
 * @access public
 * @package AdminService\View\Compiler
 * @version 1.0.0
 */
final class InterpretedTemplate implements CompiledTemplateInterface {

    /**
     * 构造方法
     *
     * @access public
     * @param array<int,mixed> $nodes 节点树
     */
    public function __construct(private array $nodes) {
    }

    /**
     * 渲染模板
     *
     * @access public
     * @param array<string,mixed> $data 模板数据
     * @return string
     */
    public function render(array $data): string {
        $out='';
        $this->renderNodes($this->nodes,$data,$out);
        return $out;
    }

    /**
     * 渲染节点列表
     *
     * @access private
     * @param array<int,mixed> $nodes 节点
     * @param array<string,mixed> $data 数据
     * @param string $out 输出
     * @return void
     */
    private function renderNodes(array $nodes,array $data,string &$out): void {
        foreach($nodes as $node) {
            if($node instanceof TextNode) {
                $out.=$node->text;
                continue;
            }
            if($node instanceof EchoNode) {
                $value=Runtime::get($data,$node->path);
                $out.=$node->escape?Runtime::escape($value):Runtime::raw($value);
                continue;
            }
            if($node instanceof IfNode) {
                $this->renderNodes(
                    Runtime::truthy($node->condition->evaluate($data))?$node->then:$node->else,
                    $data,
                    $out
                );
                continue;
            }
            if($node instanceof ForeachNode) {
                $items=Runtime::iterable(Runtime::get($data,$node->source));
                foreach($items as $key=>$value) {
                    $child=$data;
                    Runtime::assign($child,$node->item,$value);
                    if($node->key!==null)
                        Runtime::assign($child,$node->key,$key);
                    $this->renderNodes($node->body,$child,$out);
                }
            }
        }
    }

}
