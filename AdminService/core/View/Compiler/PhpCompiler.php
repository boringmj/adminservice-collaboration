<?php

namespace AdminService\View\Compiler;

use AdminService\View\Parser\Expression\Binary;
use AdminService\View\Parser\Expression\ExpressionInterface;
use AdminService\View\Parser\Expression\Literal;
use AdminService\View\Parser\Expression\Unary;
use AdminService\View\Parser\Expression\Variable;
use AdminService\View\Parser\Node\EchoNode;
use AdminService\View\Parser\Node\ForeachNode;
use AdminService\View\Parser\Node\IfNode;
use AdminService\View\Parser\Node\TextNode;
use AdminService\View\Parser\Parser;
use base\View\CodeTemplateCompilerInterface;
use base\View\CompiledTemplateInterface;
use base\View\TemplateSourceInterface;

use function var_export;

/**
 * PHP 模板编译器
 *
 * - 同一棵 AST 可生成解释产物或 PHP 闭包代码
 *
 * @access public
 * @package AdminService\View\Compiler
 * @version 1.0.0
 */
final class PhpCompiler implements CodeTemplateCompilerInterface {

    /**
     * 编译器版本
     */
    public const VERSION='2';

    /**
     * 解析器
     * @var Parser
     */
    private Parser $parser;

    /**
     * 临时变量序号
     * @var int
     */
    private int $counter=0;

    /**
     * 构造方法
     *
     * @access public
     * @param Parser|null $parser 解析器(null = 新建)
     */
    public function __construct(?Parser $parser=null) {
        $this->parser=$parser??new Parser();
    }

    /**
     * 编译为解释产物
     *
     * @access public
     * @param TemplateSourceInterface $source 模板来源
     * @return CompiledTemplateInterface
     */
    public function compile(TemplateSourceInterface $source): CompiledTemplateInterface {
        return new InterpretedTemplate($this->parser->parse(
            $source->content(),
            $source->path()??'string template'
        ));
    }

    /**
     * 生成 PHP 闭包代码
     *
     * @access public
     * @param TemplateSourceInterface $source 模板来源
     * @return string
     */
    public function code(TemplateSourceInterface $source): string {
        $this->counter=0;
        $nodes=$this->parser->parse($source->content(),$source->path()??'string template');
        return "static function(array \$data): string {\n"
            ."\$out='';\n"
            .$this->compileNodes($nodes,'$data')
            ."return \$out;\n"
            ."}";
    }

    /**
     * 生成节点代码
     *
     * @access private
     * @param array<int,mixed> $nodes 节点
     * @param string $dataVar 数据变量名
     * @return string
     */
    private function compileNodes(array $nodes,string $dataVar): string {
        $code='';
        foreach($nodes as $node) {
            if($node instanceof TextNode) {
                $code.='$out.='.var_export($node->text,true).";\n";
                continue;
            }
            if($node instanceof EchoNode) {
                $method=$node->escape?'escape':'raw';
                $code.='$out.=\AdminService\View\Runtime::'.$method
                    .'(\AdminService\View\Runtime::get('.$dataVar.','
                    .var_export($node->path,true)."));\n";
                continue;
            }
            if($node instanceof IfNode) {
                $code.='if('.$this->compileExpression($node->condition,$dataVar)."){\n"
                    .$this->compileNodes($node->then,$dataVar)
                    ."}\n";
                if($node->else!==array())
                    $code.="else{\n".$this->compileNodes($node->else,$dataVar)."}\n";
                continue;
            }
            if($node instanceof ForeachNode) {
                $index=++$this->counter;
                $items='$__items'.$index;
                $key='$__key'.$index;
                $value='$__value'.$index;
                $child='$data'.$index;
                $code.=$items.'=\AdminService\View\Runtime::iterable('
                    .'\AdminService\View\Runtime::get('.$dataVar.','
                    .var_export($node->source,true)."));\n";
                $code.='foreach('.$items.' as '.$key.'=>'.$value."){\n";
                $code.=$child.'='.$dataVar.";\n";
                $code.='\AdminService\View\Runtime::assign('.$child.','
                    .var_export($node->item,true).','.$value.");\n";
                if($node->key!==null)
                    $code.='\AdminService\View\Runtime::assign('.$child.','
                        .var_export($node->key,true).','.$key.");\n";
                $code.=$this->compileNodes($node->body,$child)."}\n";
            }
        }
        return $code;
    }

    /**
     * 生成表达式代码
     *
     * @access private
     * @param ExpressionInterface $expression 表达式
     * @param string $dataVar 数据变量名
     * @return string
     */
    private function compileExpression(ExpressionInterface $expression,string $dataVar): string {
        if($expression instanceof Literal)
            return var_export($expression->value,true);
        if($expression instanceof Variable)
            return '\AdminService\View\Runtime::get('.$dataVar.','
                .var_export($expression->path,true).')';
        if($expression instanceof Unary) {
            $operand=$this->compileExpression($expression->operand,$dataVar);
            if($expression->operator==='!')
                return '(!'.$operand.')';
            return '(-\AdminService\View\Runtime::number('.$operand.'))';
        }
        if($expression instanceof Binary)
            return '('.$this->compileExpression($expression->left,$dataVar)
                .' '.$expression->operator.' '
                .$this->compileExpression($expression->right,$dataVar).')';
        return 'false';
    }

}
