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
        $this->template_path=$template_path;
        $this->data=$data;
        $this->template_content=file_get_contents($template_path);
        $this->initialized=true;
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
        // 匹配循环标签: {{foreach $var as $key => $val}} 或 {{foreach $var as $val}}
        $pattern='/\{\{foreach\s+(\S+?)\s+as\s+(\S+?)(?:\s*=>\s*(\S+))?\}\}(.*?)\{\{\/foreach\}\}/s';
        $this->template_content=preg_replace_callback($pattern,function($matches) {
            $dataPath=trim($matches[1],'$');
            $itemVar=trim($matches[2],'$');
            $keyVar=isset($matches[3])?trim($matches[3],'$'):null;
            $loopContent=$matches[4];
            // 获取循环数据
            $loopData=$this->getDataByPath($dataPath);
            if(!is_array($loopData)) return '';
            $result='';
            foreach($loopData as $key=>$value) {
                $childData=$this->data;
                $childData[$itemVar]=$value;
                if($keyVar!==null) {
                    $childData[$keyVar]=$key;
                }
                // 创建子视图并渲染
                $childView=new self();
                $childView->initByContent($loopContent,$childData);
                $result.=$childView->render();
            }
            return $result;
        },$this->template_content);
    }

    /**
     * 处理条件结构
     * 
     * @access protected
     * @return void
     */
    protected function processConditions(): void {
        // 匹配条件标签: {{if condition}}...{{else}}...{{/if}}
        $pattern='/\{\{if\s+(.+?)\}\}(.*?)(?:\{\{else\}\}(.*?))?\{\{\/if\}\}/s';
        $this->template_content=preg_replace_callback($pattern,function($matches) {
            $condition=trim($matches[1]);
            $ifBlock=$matches[2];
            $elseBlock=$matches[3]??'';
            $conditionValue=$this->evaluateCondition($condition);
            $content=$conditionValue?$ifBlock:$elseBlock;
            $childView=new self();
            $childView->initByContent($content,$this->data);
            return $childView->render();
        },$this->template_content);
    }

    /**
     * 处理变量替换
     * 
     * @access protected
     * @return void
     */
    protected function processVariables(): void {
        // 匹配变量标签: {{$var}} 或 {{var.sub}}
        $pattern='/\{\{\s*(\$?[\w\.\[\]\'"]+)\s*\}\}/';
        $this->template_content=preg_replace_callback($pattern,function($matches) {
            $varPath=ltrim($matches[1],'$');
            $value=$this->getDataByPath($varPath);
            if(is_scalar($value)) {
                return $value;
            } elseif(is_array($value)||is_object($value)) {
                return json_encode($value,JSON_UNESCAPED_UNICODE);
            }
            return '';
        },$this->template_content);
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
        // 处理变量存在性检查
        if(preg_match('/^\$?[\w\.\[\]\'"]+$/',$condition)) {
            $value=$this->getDataByPath(ltrim($condition,'$'));
            return (bool)$value;
        }
        // 处理比较表达式
        if (preg_match('/^(.+?)\s*(==|!=|<=|>=|<|>)\s*(.+)$/',$condition,$matches)) {
            $left=trim($matches[1]);
            $operator=$matches[2];
            $right=trim($matches[3]);
            // 获取左右操作数的值
            $leftVal=$this->getValue($left);
            $rightVal=$this->getValue($right);
            // 处理字面量比较
            return $this->compareValues($leftVal,$operator,$rightVal);
        }
        return false;
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