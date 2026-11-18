<?php

namespace AdminService\View\Parser;

use AdminService\Exception;
use AdminService\View\Parser\Expression\Binary;
use AdminService\View\Parser\Expression\ExpressionInterface;
use AdminService\View\Parser\Expression\Literal;
use AdminService\View\Parser\Expression\Unary;
use AdminService\View\Parser\Expression\Variable;
use AdminService\View\Parser\Node\EchoNode;
use AdminService\View\Parser\Node\ForeachNode;
use AdminService\View\Parser\Node\IfNode;
use AdminService\View\Parser\Node\TextNode;

use function array_pop;
use function count;
use function in_array;
use function preg_match;
use function stripcslashes;
use function strlen;
use function str_starts_with;
use function substr;
use function trim;

/**
 * 模板解析器
 *
 * - 标签结构一次成树; 条件表达式使用递归下降解析
 * - 不支持的语法直接抛异常, 不静默当成 false
 *
 * @access public
 * @package AdminService\View\Parser
 * @version 1.0.0
 */
final class Parser {

    /**
     * 词法器
     * @var Lexer
     */
    private Lexer $lexer;

    /**
     * 构造方法
     *
     * @access public
     * @param Lexer|null $lexer 词法器(null = 新建)
     */
    public function __construct(?Lexer $lexer=null) {
        $this->lexer=$lexer??new Lexer();
    }

    /**
     * 解析模板
     *
     * @access public
     * @param string $source 模板内容
     * @param string $label 模板标识
     * @return array<int,mixed> 节点树
     * @throws Exception 语法错误
     */
    public function parse(string $source,string $label='template'): array {
        $root=array();
        $frames=array();
        foreach($this->lexer->tokenize($source,$label) as $token) {
            if($token['type']==='text') {
                $this->append($root,$frames,new TextNode($token['value']));
                continue;
            }
            $content=$token['value'];
            $line=$token['line'];
            if($token['raw']) {
                $this->append($root,$frames,new EchoNode($this->parsePath($content,$label,$line),false));
                continue;
            }
            if($content==='')
                $this->fail('empty tag',$label,$line);
            if($content==='else') {
                $frame=$this->top($frames,$label,$line);
                if($frame->type!=='if'||$frame->in_else)
                    $this->fail('unexpected else',$label,$line);
                $frame->in_else=true;
                continue;
            }
            if($content==='/if'||$content==='/foreach') {
                $frame=$this->pop($frames,$label,$line,substr($content,1));
                if($frame->type==='if')
                    $this->append($root,$frames,new IfNode($frame->condition,$frame->then,$frame->else));
                else
                    $this->append($root,$frames,new ForeachNode($frame->source,$frame->key,$frame->item,$frame->then));
                continue;
            }
            if(preg_match('/^if\s+(.+)$/s',$content,$matches)===1) {
                $frames[]=new Frame('if',$this->parseExpression($matches[1],$label,$line),line:$line);
                continue;
            }
            if(preg_match('/^foreach\s+(\S+)\s+as\s+(\S+?)(?:\s*=>\s*(\S+))?$/s',$content,$matches)===1) {
                $key=null;
                $item=$matches[2];
                if(isset($matches[3])&&$matches[3]!=='') {
                    $key=$item;
                    $item=$matches[3];
                }
                $frames[]=new Frame(
                    'foreach',
                    null,
                    $this->parsePath($matches[1],$label,$line),
                    $key===null?null:$this->parsePath($key,$label,$line),
                    $this->parsePath($item,$label,$line),
                    $line
                );
                continue;
            }
            if($content==='if'||$content==='foreach')
                $this->fail('missing '.$content.' expression',$label,$line);
            $this->append($root,$frames,new EchoNode($this->parsePath($content,$label,$line),true));
        }
        if($frames!==array()) {
            $frame=$frames[count($frames)-1];
            $this->fail('unclosed '.$frame->type,$label,$frame->line);
        }
        return $root;
    }

    /**
     * 解析变量路径
     *
     * - 支持 `$name`、`a.b`、`a['b']`、`a[0]`
     *
     * @access public
     * @param string $text 路径文本
     * @param string $label 模板标识
     * @param int $line 行号
     * @return array<int,int|string>
     * @throws Exception 路径非法
     */
    public function parsePath(string $text,string $label='template',int $line=0): array {
        $text=trim($text);
        if($text==='')
            $this->fail('empty variable path',$label,$line);
        if($text[0]==='$')
            $text=substr($text,1);
        $length=strlen($text);
        $offset=0;
        if(preg_match('/[A-Za-z_][A-Za-z0-9_]*/A',$text,$matches,0,$offset)!==1)
            $this->fail('invalid variable path "'.$text.'"',$label,$line);
        $path=array($matches[0]);
        $offset+=strlen($matches[0]);
        while($offset<$length) {
            $char=$text[$offset];
            if($char==='.') {
                $offset++;
                if(preg_match('/[A-Za-z0-9_]+/A',$text,$matches,0,$offset)!==1)
                    $this->fail('invalid variable path "'.$text.'"',$label,$line);
                $path[]=$matches[0];
                $offset+=strlen($matches[0]);
                continue;
            }
            if($char==='[') {
                $offset++;
                if(preg_match('/\s*(?:"((?:\\\\.|[^"\\\\])*)"|\'((?:\\\\.|[^\'\\\\])*)\'|([A-Za-z0-9_]+))\s*\]/A',$text,$matches,0,$offset)!==1)
                    $this->fail('invalid variable path "'.$text.'"',$label,$line);
                $key=($matches[1]??'')!==''?$matches[1]:(($matches[2]??'')!==''?$matches[2]:($matches[3]??''));
                $path[]=stripcslashes($key);
                $offset+=strlen($matches[0]);
                continue;
            }
            $this->fail('invalid variable path "'.$text.'"',$label,$line);
        }
        return $path;
    }

    /**
     * 解析条件表达式
     *
     * @access public
     * @param string $text 表达式
     * @param string $label 模板标识
     * @param int $line 行号
     * @return ExpressionInterface
     * @throws Exception 语法错误
     */
    public function parseExpression(string $text,string $label='template',int $line=0): ExpressionInterface {
        $tokens=$this->tokenizeExpression($text,$label,$line);
        $index=0;
        $expression=$this->parseOr($tokens,$index,$label,$line);
        if($index<count($tokens))
            $this->fail('unexpected token "'.$tokens[$index]['value'].'"',$label,$line);
        return $expression;
    }

    /**
     * 解析 OR
     *
     * @access private
     * @param array<int,array{type:string,value:mixed}> $tokens token
     * @param int $index 下标
     * @param string $label 模板标识
     * @param int $line 行号
     * @return ExpressionInterface
     */
    private function parseOr(array $tokens,int &$index,string $label,int $line): ExpressionInterface {
        $left=$this->parseAnd($tokens,$index,$label,$line);
        while(isset($tokens[$index])&&$tokens[$index]['type']==='op'&&$tokens[$index]['value']==='||') {
            $index++;
            $left=new Binary('||',$left,$this->parseAnd($tokens,$index,$label,$line));
        }
        return $left;
    }

    /**
     * 解析 AND
     *
     * @access private
     * @param array<int,array{type:string,value:mixed}> $tokens token
     * @param int $index 下标
     * @param string $label 模板标识
     * @param int $line 行号
     * @return ExpressionInterface
     */
    private function parseAnd(array $tokens,int &$index,string $label,int $line): ExpressionInterface {
        $left=$this->parseEquality($tokens,$index,$label,$line);
        while(isset($tokens[$index])&&$tokens[$index]['type']==='op'&&$tokens[$index]['value']==='&&') {
            $index++;
            $left=new Binary('&&',$left,$this->parseEquality($tokens,$index,$label,$line));
        }
        return $left;
    }

    /**
     * 解析相等比较
     *
     * @access private
     * @param array<int,array{type:string,value:mixed}> $tokens token
     * @param int $index 下标
     * @param string $label 模板标识
     * @param int $line 行号
     * @return ExpressionInterface
     */
    private function parseEquality(array $tokens,int &$index,string $label,int $line): ExpressionInterface {
        $left=$this->parseRelational($tokens,$index,$label,$line);
        while(isset($tokens[$index])&&$tokens[$index]['type']==='op'
            &&in_array($tokens[$index]['value'],array('==','!='),true)) {
            $operator=$tokens[$index]['value'];
            $index++;
            $left=new Binary($operator,$left,$this->parseRelational($tokens,$index,$label,$line));
        }
        return $left;
    }

    /**
     * 解析大小比较
     *
     * @access private
     * @param array<int,array{type:string,value:mixed}> $tokens token
     * @param int $index 下标
     * @param string $label 模板标识
     * @param int $line 行号
     * @return ExpressionInterface
     */
    private function parseRelational(array $tokens,int &$index,string $label,int $line): ExpressionInterface {
        $left=$this->parseUnary($tokens,$index,$label,$line);
        while(isset($tokens[$index])&&$tokens[$index]['type']==='op'
            &&in_array($tokens[$index]['value'],array('<','>','<=','>='),true)) {
            $operator=$tokens[$index]['value'];
            $index++;
            $left=new Binary($operator,$left,$this->parseUnary($tokens,$index,$label,$line));
        }
        return $left;
    }

    /**
     * 解析一元表达式
     *
     * @access private
     * @param array<int,array{type:string,value:mixed}> $tokens token
     * @param int $index 下标
     * @param string $label 模板标识
     * @param int $line 行号
     * @return ExpressionInterface
     */
    private function parseUnary(array $tokens,int &$index,string $label,int $line): ExpressionInterface {
        if(isset($tokens[$index])&&$tokens[$index]['type']==='op'
            &&in_array($tokens[$index]['value'],array('!','-'),true)) {
            $operator=$tokens[$index]['value'];
            $index++;
            return new Unary($operator,$this->parseUnary($tokens,$index,$label,$line));
        }
        return $this->parsePrimary($tokens,$index,$label,$line);
    }

    /**
     * 解析基本表达式
     *
     * @access private
     * @param array<int,array{type:string,value:mixed}> $tokens token
     * @param int $index 下标
     * @param string $label 模板标识
     * @param int $line 行号
     * @return ExpressionInterface
     */
    private function parsePrimary(array $tokens,int &$index,string $label,int $line): ExpressionInterface {
        if(!isset($tokens[$index]))
            $this->fail('unexpected end of expression',$label,$line);
        $token=$tokens[$index];
        if($token['type']==='op'&&$token['value']==='(') {
            $index++;
            $expression=$this->parseOr($tokens,$index,$label,$line);
            if(!isset($tokens[$index])||$tokens[$index]['value']!==')')
                $this->fail('missing closing parenthesis',$label,$line);
            $index++;
            return $expression;
        }
        if($token['type']==='number'||$token['type']==='string'||$token['type']==='literal') {
            $index++;
            return new Literal($token['value']);
        }
        if($token['type']==='path') {
            $index++;
            return new Variable($token['value']);
        }
        $this->fail('unexpected token "'.$token['value'].'"',$label,$line);
    }

    /**
     * 条件表达式的词法分析
     *
     * @access private
     * @param string $text 表达式
     * @param string $label 模板标识
     * @param int $line 行号
     * @return array<int,array{type:string,value:mixed}>
     * @throws Exception 语法错误
     */
    private function tokenizeExpression(string $text,string $label,int $line): array {
        $tokens=array();
        $length=strlen($text);
        $offset=0;
        while($offset<$length) {
            if($text[$offset]===' '||$text[$offset]==="\t"||$text[$offset]==="\r"||$text[$offset]==="\n") {
                $offset++;
                continue;
            }
            $rest=substr($text,$offset);
            foreach(array('||','&&','==','!=','<=','>=','<','>','!','(',')','-') as $operator) {
                if(str_starts_with($rest,$operator)) {
                    $tokens[]=array('type'=>'op','value'=>$operator);
                    $offset+=strlen($operator);
                    continue 2;
                }
            }
            if(preg_match('/^(?:\d+(?:\.\d+)?|\.\d+)/A',$rest,$matches)===1) {
                $tokens[]=array('type'=>'number','value'=>$matches[0]+0);
                $offset+=strlen($matches[0]);
                continue;
            }
            if($rest[0]==='"'||$rest[0]==="'") {
                if(preg_match('/^("(?:\\\\.|[^"\\\\])*"|\'(?:\\\\.|[^\'\\\\])*\')/As',$rest,$matches)!==1)
                    $this->fail('unterminated string',$label,$line);
                $tokens[]=array('type'=>'string','value'=>stripcslashes(substr($matches[0],1,-1)));
                $offset+=strlen($matches[0]);
                continue;
            }
            if(preg_match('/^\$?[A-Za-z_][A-Za-z0-9_]*(?:(?:\.[A-Za-z0-9_]+)|(?:\[\s*(?:"(?:\\\\.|[^"\\\\])*"|\'(?:\\\\.|[^\'\\\\])*\'|[A-Za-z0-9_]+)\s*\]))*/A',$rest,$matches)===1) {
                // 字面量只认小写拼写, 其余拼写按变量路径处理(数据键 True / NULL / False 不被吞)
                if(in_array($matches[0],array('true','false','null'),true)) {
                    $value=$matches[0]==='true'?true:($matches[0]==='false'?false:null);
                    $tokens[]=array('type'=>'literal','value'=>$value);
                } else {
                    $tokens[]=array('type'=>'path','value'=>$this->parsePath($matches[0],$label,$line));
                }
                $offset+=strlen($matches[0]);
                continue;
            }
            $this->fail('unexpected token "'.$rest[0].'"',$label,$line);
        }
        if($tokens===array())
            $this->fail('empty condition',$label,$line);
        return $tokens;
    }

    /**
     * 追加节点
     *
     * @access private
     * @param array<int,mixed> $root 根节点
     * @param array<int,Frame> $frames 栈
     * @param mixed $node 节点
     * @return void
     */
    private function append(array &$root,array $frames,mixed $node): void {
        if($frames===array()) {
            $root[]=$node;
            return;
        }
        $frames[count($frames)-1]->add($node);
    }

    /**
     * 取栈顶
     *
     * @access private
     * @param array<int,Frame> $frames 栈
     * @param string $label 模板标识
     * @param int $line 行号
     * @return Frame
     * @throws Exception 栈为空
     */
    private function top(array $frames,string $label,int $line): Frame {
        if($frames===array())
            $this->fail('unexpected tag',$label,$line);
        return $frames[count($frames)-1];
    }

    /**
     * 弹出并校验栈顶
     *
     * @access private
     * @param array<int,Frame> $frames 栈
     * @param string $label 模板标识
     * @param int $line 行号
     * @param string $type 期望类型
     * @return Frame
     * @throws Exception 栈为空或类型不匹配
     */
    private function pop(array &$frames,string $label,int $line,string $type): Frame {
        if($frames===array())
            $this->fail('unexpected closing tag',$label,$line);
        $frame=$frames[count($frames)-1];
        if($frame->type!==$type)
            $this->fail('unexpected closing tag /'.$type,$label,$line);
        array_pop($frames);
        return $frame;
    }

    /**
     * 抛出语法异常
     *
     * @access private
     * @param string $message 消息
     * @param string $label 模板标识
     * @param int $line 行号
     * @return never
     * @throws Exception
     */
    private function fail(string $message,string $label,int $line): never {
        throw new Exception('Template syntax error: '.$message.' in '.$label.($line>0?' line '.$line:''));
    }

}
