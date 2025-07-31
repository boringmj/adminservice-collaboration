<?php

namespace AdminService;

use base\Validator as BaseValidator;

/**
 * 表单验证器类
 * 
 * @access public
 * @package AdminService
 * @version 1.0.0
 */
class FormValidator extends BaseValidator {

    /**
     * 验证规则对应的错误消息模板
     * @var array
     */
    protected array $messages=[
        'required'      => '{field}不能为空',
        'email'         => '{field}必须是有效的邮箱地址',
        'min'           => '{field}必须大于等于{param}',
        'max'           => '{field}必须小于等于{param}',
        'min_length'    => '{field}长度不能小于{param}',
        'max_length'    => '{field}长度不能大于{param}',
        'numeric'       => '{field}必须是数字',
        'integer'       => '{field}必须是整数',
        'url'           => '{field}必须是有效的URL',
        'regex'         => '{field}格式不正确',
        'in'            => '{field}必须在{param}之中',
        'not_in'        => '{field}不能在{param}之中',
        'same'          => '{field}必须和{param}相同',
        'different'     => '{field}必须和{param}不同',
        'date'          => '{field}必须是有效的日期',
        'after'         => '{field}必须在{param}之后',
        'before'        => '{field}必须在{param}之前',
        'between'       => '{field}必须在{min}和{max}之间',
        'not_between'   => '{field}不能在{min}和{max}之间',
        'ip'            => '{field}必须是有效的IP地址',
        'phone'         => '{field}必须是有效的手机号',
        'json'          => '{field}必须是有效的JSON字符串',
        'array'         => '{field}必须是数组',
    ];

    /**
     * 实现验证规则检查
     * 
     * @param string $field 字段名称
     * @param string $rule 规则名称
     * @param mixed $value 字段值
     * @param mixed $param 规则参数
     * @return bool
     */
    protected function checkRule(string $field,string $rule,mixed $value,mixed $param=null): bool {
        $method='validate'.str_replace(' ','',ucwords(str_replace('_',' ',$rule)));
        if(method_exists($this,$method)) {
            return $this->$method($field,$value,$param);
        }
        // 处理未知规则
        $this->error($field,[
            'rule'=>$rule,
            'msg'=>"验证规则 '{$rule}' 不存在"
        ]);
        return false;
    }

    /**
     * 替换消息模板中的占位符
     * 
     * @param string $template 消息模板
     * @param array $context 替换上下文
     * @return string
     */
    protected function replacePlaceholders(string $template,array $context): string {
        foreach ($context as $key=>$val) {
            $placeholder='{'.$key.'}';
            if(strpos($template,$placeholder)!==false) {
                $template=str_replace($placeholder,$this->stringify($val),$template);
            }
        }
        return $template;
    }

    /**
     * 将值转换为可读字符串
     * 
     * @param mixed $value
     * @return string
     */
    protected function stringify(mixed $value): string {
        if(is_array($value)) {
            return implode(',',$value);
        }
        if($value===null) {
            return '空';
        }
        if(is_bool($value)) {
            return $value?'是':'否';
        }
        return (string)$value;
    }

    /**
     * 添加验证错误
     * 
     * @param string $field 字段名
     * @param string $rule 规则名
     * @param mixed $param 规则参数
     * @return bool
     */
    protected function addError(string $field,string $rule,mixed $param=null): bool {
        $template=$this->messages[$rule]??'{field}验证失败';
        $context=[
            'field'=>$field,
            'param'=>$param,
            'value'=>$this->data[$field]??null
        ];
        // 特殊处理between规则
        if(in_array($rule,['between','not_between'])&&$param!==null) {
            list($context['min'],$context['max'])=explode(',',$param);
        }
        $message=$this->replacePlaceholders($template,$context);
        $this->error($field,[
            'rule'=>$rule,
            'msg'=>$message
        ]);
        return false;
    }

    /**
     * 获取字符长度
     * 
     * @param string $value 字符串
     * @return int
     */
    protected function stringLength(string $value): int {
        // 判断是否支持mbstring函数
        if(function_exists('mb_strlen'))
            return mb_strlen($value);
        // 检查是否为有效的 UTF-8 字符串（正则方式）
        if(!preg_match('//u',$value))
            throw new Exception('The given string is not valid UTF-8.');
        // 使用正则统计字符数量
        preg_match_all('/./u',$value,$matches);
        return count($matches[0]);
    }

    // ===================== 验证规则实现 =====================

    protected function validateRequired(string $field,$value): bool {
        if(is_null($value)) {
            return $this->addError($field,'required');
        }
        
        if(is_string($value)&&trim($value)==='') {
            return $this->addError($field,'required');
        }
        
        if(is_array($value)&&count($value)<1) {
            return $this->addError($field,'required');
        }
        
        return true;
    }

    protected function validateEmail(string $field,$value): bool {
        if($value&&!filter_var($value,FILTER_VALIDATE_EMAIL)) {
            return $this->addError($field,'email');
        }
        return true;
    }

    protected function validateMin(string $field,$value,$param): bool {
        if(is_numeric($value)&&$value<$param) {
            return $this->addError($field,'min',$param);
        }
        return true;
    }

    protected function validateMax(string $field,$value,$param): bool {
        if(is_numeric($value)&&$value>$param) {
            return $this->addError($field,'max',$param);
        }
        return true;
    }

    protected function validateMinLength(string $field,$value,$param): bool {
        if(is_string($value)&&$this->stringLength($value)<$param) {
            return $this->addError($field,'min_length',$param);
        }
        return true;
    }

    protected function validateMaxLength(string $field,$value,$param): bool {
        if(is_string($value)&&$this->stringLength($value)>$param) {
            return $this->addError($field,'max_length',$param);
        }
        return true;
    }

    protected function validateNumeric(string $field,$value): bool {
        if(!is_numeric($value)) {
            return $this->addError($field,'numeric');
        }
        return true;
    }

    protected function validateInteger(string $field,$value): bool {
        if(!filter_var($value,FILTER_VALIDATE_INT)) {
            return $this->addError($field,'integer');
        }
        return true;
    }

    protected function validateUrl(string $field,$value): bool {
        if($value&&!filter_var($value,FILTER_VALIDATE_URL)) {
            return $this->addError($field,'url');
        }
        return true;
    }

    protected function validateRegex(string $field,$value,$param): bool {
        if(!preg_match($param,$value)) {
            return $this->addError($field,'regex',$param);
        }
        return true;
    }

    protected function validateIn(string $field,$value,$param): bool {
        $options=explode(',',$param);
        if(!in_array($value,$options)) {
            return $this->addError($field,'in',$param);
        }
        return true;
    }

    protected function validateNotIn(string $field,$value,$param): bool {
        $options=explode(',',$param);
        if(in_array($value,$options)) {
            return $this->addError($field,'not_in',$param);
        }
        return true;
    }

    protected function validateSame(string $field,$value,$param): bool {
        $otherValue=$this->data[$param]??null;
        if($value!==$otherValue) {
            return $this->addError($field,'same',$param);
        }
        return true;
    }

    protected function validateDifferent(string $field,$value,$param): bool {
        $otherValue=$this->data[$param]??null;
        if($value===$otherValue) {
            return $this->addError($field,'different',$param);
        }
        return true;
    }

    protected function validateDate(string $field,$value): bool {
        if(strtotime($value)===false) {
            return $this->addError($field,'date');
        }
        return true;
    }

    protected function validateAfter(string $field,$value,$param): bool {
        $time=strtotime($value);
        $paramTime=strtotime($param);
        if($time===false||$paramTime===false||$time<=$paramTime) {
            return $this->addError($field,'after',$param);
        }
        return true;
    }

    protected function validateBefore(string $field,$value,$param): bool {
        $time=strtotime($value);
        $paramTime=strtotime($param);
        if($time===false||$paramTime===false||$time>=$paramTime) {
            return $this->addError($field,'before',$param);
        }
        return true;
    }

    protected function validateBetween(string $field,$value,$param): bool {
        list($min,$max)=explode(',',$param);
        if(is_numeric($value)) {
            if($value<$min||$value>$max) {
                return $this->addError($field,'between',$param);
            }
        } elseif(is_string($value)) {
            $length=$this->stringLength($value);
            if($length<$min||$length>$max) {
                return $this->addError($field,'between',$param);
            }
        }
        return true;
    }

    protected function validateNotBetween(string $field,$value,$param): bool {
        list($min,$max)=explode(',',$param);
        if(is_numeric($value)) {
            if($value>=$min&&$value<=$max) {
                return $this->addError($field,'not_between',$param);
            }
        } elseif(is_string($value)) {
            $length=$this->stringLength($value);
            if($length>=$min&&$length<=$max) {
                return $this->addError($field,'not_between',$param);
            }
        }
        return true;
    }

    protected function validateIp(string $field,$value): bool {
        if(!filter_var($value,FILTER_VALIDATE_IP)) {
            return $this->addError($field,'ip');
        }
        return true;
    }

    protected function validatePhone(string $field,$value): bool {
        if(!preg_match('/^1[3-9]\d{9}$/',$value)) {
            return $this->addError($field,'phone');
        }
        return true;
    }

    protected function validateJson(string $field,$value): bool {
        if(!is_string($value)) {
            return $this->addError($field,'json');
        }
        json_decode($value);
        if(json_last_error()!==JSON_ERROR_NONE) {
            return $this->addError($field,'json');
        }
        return true;
    }

    protected function validateArray(string $field,$value): bool {
        if(!is_array($value)) {
            return $this->addError($field,'array');
        }
        return true;
    }
}