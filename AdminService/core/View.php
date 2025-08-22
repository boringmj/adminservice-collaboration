<?php

namespace AdminService;

use base\View as BaseView;

final class View extends BaseView {

    /**
     * 模板文件路径
     * @var string
     */
    protected string $template_content='';

    /**
     * 模板数据
     * @var array
     */
    protected bool $initialized=false;

    /**
     * 初始化方法
     *
     * @access public
     * @param string $template_path 模板文件路径
     * @param array $data 需要传递给模板的数据
     * @return void
     * @throws Exception
     */
    public function init(string $template_path,array $data=array()): void {
        if(!is_file($template_path))
            throw new Exception('Template file not found.',100301,
                ['template_path'=>$template_path]
            );
        $this->initWithContent(
            file_get_contents($template_path),
            $data
        );
    }

     /**
     * 通过直接传入模板内容完成初始化
     * 
     * @access public
     * @param string $template_content 模板内容
     * @param array $data 需要传递给模板的数据
     * @return void
     */
    public function initWithContent(string $template_content,array $data=array()): void {
        $this->initByContent($template_content,$data);
    }

    /**
     * 渲染模板
     * 
     * @access public
     * @return string
     */
    public function render(): string {
        if(!$this->initialized)
            throw new Exception('View not initialized.',100303);
        // 递归处理所有模板标签(循环、条件、变量)
        $this->processTemplateTags();
        return $this->template_content;
    }

    /**
     * 递归处理模板中的所有标签
     * 
     * @access protected
     * @return void
     */
    protected function processTemplateTags(): void {
        $maxDepth=16; // 防止无限递归(最高深度)
        $depth=0;
        while(
            ($depth<$maxDepth)&&
            ($this->containsLoop()||$this->containsCondition()||$this->containsVariables())
        ) {
            $this->processLoops();
            $this->processConditions();
            $this->processVariables();
            $depth++;
        }
    }

    /**
     * 处理循环结构
     * 
     * @access protected
     * @return void
     */
    protected function processLoops(): void {
        $pattern='/\{\{foreach\s+(\S+?)\s+as\s+(\S+?)(?:\s*=>\s*(\S+))?\}\}/';
        $content=$this->template_content;
        $offset=0;
        $result='';
        while(preg_match($pattern,$content,$startMatches,PREG_OFFSET_CAPTURE,$offset)) {
            $startPos=$startMatches[0][1];
            $before=substr($content,$offset,$startPos-$offset);
            $result.=$before;
            $loopStart=$startPos+strlen($startMatches[0][0]);
            // 查找对应的结束标签
            $endPos=$this->findMatchingEndTag($content,$loopStart,'foreach');
            if ($endPos===false) break;
            $loopBlock=substr($content,$loopStart,$endPos-$loopStart);
            // 解析变量
            $dataPath=trim($startMatches[1][0],'$');
            $keyVar=null;
            $itemVar=null;
            if(isset($startMatches[3][0])&&!empty(trim($startMatches[3][0]))) {
                $keyVar=trim($startMatches[2][0],'$');
                $itemVar=trim($startMatches[3][0],'$');
            } else {
                $itemVar=trim($startMatches[2][0],'$');
            }
            $loopData=$this->getDataByPath($dataPath);
            if (!is_array($loopData)) {
                $result.='';
            } else {
                foreach($loopData as $loopKey=>$loopValue) {
                    $childData=$this->data;
                    $this->assignVariable($childData,$itemVar,$loopValue);
                    if($keyVar!==null)
                        $this->assignVariable($childData,$keyVar,$loopKey);
                    $childView=new self();
                    $childView->initByContent($loopBlock,$childData);
                    $result.=$childView->render();
                }
            }
            $offset=$endPos+strlen('{{/foreach}}');
        }
        $result.=substr($content,$offset);
        $this->template_content=$result;
    }

    /**
     * 查找嵌套标签的结束位置
     * 
     * @access protected
     * @param string $content
     * @param int $start
     * @param string $tag
     * @return false|int
     */
    protected function findMatchingEndTag(string $content,int $start,string $tag): int|false {
        $openTag='{{'.$tag;
        $closeTag='{{/'.$tag.'}}';
        $pos=$start;
        $depth=1;
        while($depth>0) {
            $nextOpen=strpos($content,$openTag,$pos);
            $nextClose=strpos($content,$closeTag,$pos);
            if($nextClose===false) return false;
            if($nextOpen!==false&&$nextOpen<$nextClose) {
                $depth++;
                $pos=$nextOpen+strlen($openTag);
            } else {
                $depth--;
                $pos=$nextClose+strlen($closeTag);
            }
        }
        return $pos-strlen($closeTag);
    }

    /**
     * 分配变量到数据(支持点分隔路径)
     * 
     * @access protected
     * @param array &$data 目标数据数组
     * @param string $path 点分隔的路径
     * @param mixed $value 要分配的值
     */
    protected function assignVariable(array &$data,string $path,$value): void {
        // 如果路径不含点号，直接赋值
        if(strpos($path,'.')===false) {
            $data[$path]=$value;
            return;
        }
        // 处理点分隔路径
        $keys=explode('.',$path);
        $current=&$data;
        // 遍历路径(除最后一个键外)
        for($i=0; $i<count($keys)-1;$i++) {
            $key=$keys[$i];
            if(!isset($current[$key])) {
                $current[$key]=[];
            }
            $current=&$current[$key];
        }
        // 分配最终值
        $finalKey=end($keys);
        $current[$finalKey]=$value;
    }

    /**
     * 查找嵌套if标签的当前层级else位置
     * 
     * @access protected
     * @param string $content
     * @param int $start
     * @return false|int
     */
    protected function findMatchingElseTag(string $content,int $start): int|false {
        $openTag='{{if ';
        $elseTag='{{else}}';
        $closeTag='{{/if}}';
        $pos=$start;
        $depth=1;
        while($depth>0) {
            $nextOpen=strpos($content,$openTag,$pos);
            $nextElse=strpos($content,$elseTag,$pos);
            $nextClose=strpos($content,$closeTag,$pos);
            // 找到最近的标签
            $tags=array_filter([
                'open'=>$nextOpen,
                'else'=>$nextElse,
                'close'=>$nextClose
            ],fn($v)=>$v!==false);
            if(empty($tags)) return false;
            $minTag=array_search(min($tags),$tags);
            switch($minTag) {
                case 'open':
                    $depth++;
                    $pos=$nextOpen+strlen($openTag);
                    break;
                case 'else':
                    if($depth===1) return $nextElse;
                    $pos=$nextElse+strlen($elseTag);
                    break;
                case 'close':
                    $depth--;
                    $pos=$nextClose+strlen($closeTag);
                    break;
            }
        }
        return false;
    }

    /**
     * 处理条件结构
     * 
     * @access protected
     * @return void
     */
    protected function processConditions(): void {
        $content=$this->template_content;
        $offset=0;
        $result='';
        while(($startPos=strpos($content,'{{if ',$offset))!==false) {
            // 处理前面的内容
            $result.=substr($content,$offset,$startPos-$offset);
            // 获取条件表达式
            $condEnd=strpos($content,'}}',$startPos);
            if($condEnd===false) break;
            $condition=trim(substr($content,$startPos+5,$condEnd-($startPos+5)));
            // 查找匹配的结束标签
            $blockStart=$condEnd+2;
            $endPos=$this->findMatchingEndTag($content,$blockStart,'if');
            if($endPos===false) break;
            // 提取if/else内容
            $block=substr($content,$blockStart,$endPos-$blockStart);
            $elsePos=$this->findMatchingElseTag($content,$blockStart);
            if($elsePos!==false&&$elsePos<$endPos) {
                $ifBlock=substr($content,$blockStart,$elsePos-$blockStart);
                $elseBlock=substr($content,$elsePos+strlen('{{else}}'),$endPos-($elsePos+strlen('{{else}}')));
            } else {
                $ifBlock=$block;
                $elseBlock='';
            }
            // 评估条件
            $conditionValue=$this->evaluateCondition($condition);
            $childView=new self();
            $childView->initByContent($conditionValue?$ifBlock:$elseBlock,$this->data);
            $result.=$childView->render();
            $offset=$endPos+strlen('{{/if}}');
        }
        $result.=substr($content,$offset);
        $this->template_content=$result;
    }

    /**
     * 处理变量替换
     * 
     * @access protected
     * @return void
     */
    protected function processVariables(): void {
        // 支持三种标签：{{var}}(转义)，{{{var}}} 或 {{!var}}(不转义)
        $patterns=[
            // 不转义标签：{{{var}}} 或 {{!var}}
            '/\{\{\{\s*(\$?[\w\.\[\]\'"]+)\s*\}\}\}/'=>function($matches) {
                $varPath=ltrim($matches[1],'$');
                $value=$this->getDataByPath($varPath);
                if(is_scalar($value)) {
                    return (string)$value;
                } elseif(is_array($value)||is_object($value)) {
                    return json_encode($value, JSON_UNESCAPED_UNICODE);
                }
                return '';
            },
            '/\{\{!\s*(\$?[\w\.\[\]\'"]+)\s*\}\}/'=>function($matches) {
                $varPath=ltrim($matches[1], '$');
                $value=$this->getDataByPath($varPath);
                if(is_scalar($value)) {
                    return (string)$value;
                } elseif(is_array($value)||is_object($value)) {
                    return json_encode($value,JSON_UNESCAPED_UNICODE);
                }
                return '';
            },
            // 普通变量标签：{{var}}(默认转义)
            '/\{\{\s*(\$?[\w\.\[\]\'"]+)\s*\}\}/'=>function($matches) {
                $varPath=ltrim($matches[1], '$');
                $value=$this->getDataByPath($varPath);
                if(is_scalar($value)) {
                    if(is_string($value))
                        $value=htmlspecialchars($value,ENT_QUOTES,'UTF-8');
                    return $value;
                } elseif(is_array($value)||is_object($value)) {
                    return json_encode($value,JSON_UNESCAPED_UNICODE);
                }
                return '';
            }
        ];
        foreach($patterns as $pattern=>$callback) {
            $this->template_content=preg_replace_callback($pattern,$callback,$this->template_content);
        }
    }

    /**
     * 使用字符串内容初始化视图
     * 
     * @access public
     * @param string $content 模板内容
     * @param array $data 需要传递给模板的数据
     * @return void
     */
    public function initByContent(string $content,array $data): void {
        $this->template_content=$content;
        $this->data=$data;
        $this->initialized=true;
    }

    /**
     * 根据点分路径获取数据
     * 
     * @access protected
     * @param string $path 点分路径或数组语法路径
     * @return mixed
     */
    protected function getDataByPath(string $path): mixed {
        // 处理数组语法如：data['key'] 或 data["key"]
        if(preg_match_all('/(\w+)(?:\[["\']?(\w+)["\']?\])?/',$path,$matches,PREG_SET_ORDER)) {
            $data=$this->data;
            foreach($matches as $match) {
                $key=$match[1];
                $subKey=$match[2]??null;
                if(is_array($data)) {
                    if(isset($data[$key]))
                        $data=$data[$key];
                    else
                        return null;
                } elseif(is_object($data)) {
                    if(isset($data->$key)) {
                        $data=$data->$key;
                    } else {
                        return null;
                    }
                }
                // 如果有子键，则进一步获取
                if($subKey!==null) {
                    if (is_array($data)) {
                        $data=$data[$subKey]??null;
                    } elseif(is_object($data)) {
                        $data=$data->$subKey??null;
                    }
                }
            }
            return $data;
        }
        // 处理点语法
        $keys=explode('.',$path);
        $data=$this->data;
        foreach($keys as $key) {
            if(is_array($data)) {
                if(isset($data[$key])) {
                    $data=$data[$key];
                } else {
                    return null;
                }
            } elseif(is_object($data)) {
                if(isset($data->$key)) {
                    $data=$data->$key;
                } else {
                    return null;
                }
            } else {
                return null;
            }
        }
        return $data;
    }

    /**
     * 评估条件表达式
     * 
     * @access protected
     * @param string $condition 条件表达式
     * @return bool
     */
    protected function evaluateCondition(string $condition): bool {
        // 支持的运算符及优先级
        $ops=[
            '!'=>4,
            '=='=>3,'!='=>3,'<='=>3,'>='=>3,'<'=>3,'>'=>3,
            '&&'=>2,
            '||'=>1,
            '('=>0,')'=>0
        ];
        // 变量替换为实际值
        $condition=preg_replace_callback(
            '/(\$[\w\.]+(\[.*?\])?|[a-zA-Z_][\w\.]*(\[.*?\])?)/',
            function($matches) {
                $varPath=ltrim($matches[1],'$');
                // 排除数字和布尔字面量
                if(is_numeric($varPath)||in_array(strtolower($varPath),['true','false','null']))
                    return $matches[0];
                $val = $this->getDataByPath($varPath);
                if(is_bool($val)) return $val?'true':'false';
                if(is_null($val)) return 'null';
                if(is_numeric($val)) return $val;
                if(is_string($val)) return '"'.addslashes($val).'"';
                return 'false';
            },
            $condition
        );
        // 词法分析
        $tokens=[];
        $pattern='/(\|\||&&|==|!=|<=|>=|<|>|\(|\)|!|true|false|null|"(?:\\.|[^"])*"|\'(?:\\.|[^\'])*\'|\d+|\w+)/';
        preg_match_all($pattern,$condition,$matches);
        foreach($matches[0] as $token) {
            $tokens[]=$token;
        }
        // Shunting Yard算法：中缀转后缀(逆波兰式)
        $output=[];
        $stack=[];
        foreach($tokens as $token) {
            if(preg_match('/^(true|false|null|".*"|\'.*\'|\d+)$/',$token)) {
                $output[]=$token;
            } elseif(isset($ops[$token])) {
                if($token=='(') {
                    $stack[]=$token;
                } elseif($token==')') {
                    while(!empty($stack)&&end($stack)!='(') {
                        $output[]=array_pop($stack);
                    }
                    // 弹出左括号
                    array_pop($stack);
                } else {
                    while(
                        !empty($stack)&&isset($ops[end($stack)])
                        &&$ops[end($stack)]>=$ops[$token]&&end($stack)!='('
                    ) {
                        $output[]=array_pop($stack);
                    }
                    $stack[]=$token;
                }
            } else {
                // 未知token视为false
                $output[]='false';
            }
        }
        while(!empty($stack)) {
            $output[]=array_pop($stack);
        }
        // 计算逆波兰式
        $calc=[];
        foreach ($output as $token) {
            switch($token) {
                case 'true': $calc[]=true; break;
                case 'false': $calc[]=false; break;
                case 'null': $calc[]=null; break;
                case '!':
                    $a=array_pop($calc);
                    $calc[]=!$a;
                    break;
                case '&&':
                    $b=array_pop($calc);
                    $a=array_pop($calc);
                    $calc[]=$a&&$b;
                    break;
                case '||':
                    $b=array_pop($calc);
                    $a=array_pop($calc);
                    $calc[]=$a||$b;
                    break;
                case '==':
                    $b=array_pop($calc);
                    $a=array_pop($calc);
                    $calc[]=$a==$b;
                    break;
                case '!=':
                    $b=array_pop($calc);
                    $a=array_pop($calc);
                    $calc[]=$a!=$b;
                    break;
                case '<':
                    $b=array_pop($calc);
                    $a=array_pop($calc);
                    $calc[]=$a<$b;
                    break;
                case '>':
                    $b=array_pop($calc);
                    $a=array_pop($calc);
                    $calc[]=$a>$b;
                    break;
                case '<=':
                    $b=array_pop($calc);
                    $a=array_pop($calc);
                    $calc[]=$a<=$b;
                    break;
                case '>=':
                    $b=array_pop($calc);
                    $a=array_pop($calc);
                    $calc[]=$a>=$b;
                    break;
                default:
                    // 字符串或数字
                    if(preg_match('/^"(.*)"$/',$token,$m)) {
                        $calc[]=$m[1];
                    } elseif(preg_match("/^'(.*)'$/",$token,$m)) {
                        $calc[]=$m[1];
                    } elseif(is_numeric($token)) {
                        $calc[]=$token+0;
                    }
                    break;
            }
        }
        return !empty($calc)?(bool)array_pop($calc):false;
    }

    /**
     * 获取值(支持变量或字面量)
     * 
     * @access protected
     * @param string $input 输入字符串
     * @return mixed 返回值
     */
    protected function getValue(string $input) {
        // 字符串字面量
        if(preg_match('/^[\'"](.*)[\'"]$/',$input,$matches))
            return $matches[1];
        // 布尔字面量
        if(strtolower($input)==='true') return true;
        if(strtolower($input)==='false') return false;
        if(strtolower($input)==='null') return null;
        // 数字字面量
        if(is_numeric($input)) return $input+0;
        // 变量
        return $this->getDataByPath(ltrim($input,'$'));
    }

    /**
     * 比较两个值
     * 
     * @access protected
     * @param mixed $left 左侧值
     * @param string $operator 比较运算符
     * @param mixed $right 右侧值
     * @return bool 返回比较结果
     */
    protected function compareValues(mixed $left,string $operator,$right): bool {
        switch($operator) {
            case '==': return $left==$right;
            case '!=': return $left!=$right;
            case '<':  return $left<$right;
            case '>':  return $left>$right;
            case '<=': return $left<=$right;
            case '>=': return $left>=$right;
            default: return false;
        }
    }

    /**
     * 检查是否包含循环标签
     * 
     * @access protected
     * @return bool
     */
    protected function containsLoop(): bool {
        return strpos($this->template_content,'{{foreach')!==false;
    }

    /**
     * 检查是否包含条件标签
     * 
     * @access protected
     * @return bool
     */
    protected function containsCondition(): bool {
        return strpos($this->template_content,'{{if')!==false;
    }

    /**
     * 检查是否包含变量标签
     * 
     * @access protected
     * @return bool
     */
    protected function containsVariables(): bool {
        return preg_match('/\{\{\s*(\$?[\w\.\[\]\'"]+)\s*\}\}/',$this->template_content);
    }
}