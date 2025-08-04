<?php

namespace base;

abstract class Validator {
    /**
     * 验证数据
     * @var array
     */
    protected array $data=[];

    /**
     * 错误信息
     * @var array
     */
    protected array $errors=[];

    /**
     * 验证规则集
     * @var array
     */
    protected array $rules=[];

    public function __construct(array $data=[],$rules=[]) {
        $this->data=$data;
        // 外部规则覆盖默认规则
        $this->rules=array_merge($this->rules(),$rules);
    }

    /**
     * 验证规则集
     * 
     * @abstract
     * @return array
     */
    protected function rules(): array {
        return $this->rules;
    }

    /**
     * 验证数据
     * 
     * @param array $data 待验证的数据,如果为空则使用构造时传入的数据
     * @param array $rules 验证规则,如果为空则使用构造时传入的规则
     * @return bool
     */
    public function validate(array $data=[],array $rules=[]): bool {
        if(!empty($data))
            $this->data=$data;
        if(!empty($rules))
            $this->rules=$rules;
        $this->errors=[];
        foreach($this->rules as $field=>$rule_list) {
            $value=$this->data[$field]??null;
            $ruleList=is_array($rule_list)?$rule_list:explode('|',$rule_list);
            foreach($ruleList as $rule) {
                [$ruleName,$param]=explode(':',$rule,2)+[null,null];
                $this->checkRule($field,$ruleName,$value,$param);
            }
        }
        return empty($this->errors);
    }

    /**
     * 验证规则是否满足要求
     * 
     * @param string $field 字段名称
     * @param string $rule 规则名称
     * @param mixed $value 待验证的值
     * @param mixed $param 验证规则参数
     * @return bool
     */
    abstract protected function checkRule(string $field,string $rule,mixed $value,mixed $param=null): bool;

    /**
     * 脱敏数据
     * 
     * @param array $data 待脱敏的数据
     * @return array
     */
    abstract public function sanitize(array $data): array;

    /**
     * 添加错误
     * 
     * @param string $field 字段名称
     * @param array $error 错误信息
     * @return self
     */
    protected function error(string $field,array $error): self {
        // 校验错误信息包含msg
        if(!isset($error['msg']))
            $error['msg']='unknown error';
        $this->errors[$field][]=$error;
        return $this;
    }

    /**
     * 获取验证错误信息(请严格确认信息安全后才写入日志或对外暴露)
     * 
     * @param bool $only_msg 是否只显示错误消息(默认为开启)
     * @param bool $sensitive 是否开启脱敏(默认为开启)
     * @return array
     */
    public function errors(bool $only_msg=true,bool $sensitive=true): array {
        // 如果只允许显示消息,则不需要脱敏浪费性能
        if($only_msg)
            return array_map(function($v){
                return array_map(fn($error)=>$error['msg'],$v);
            },$this->errors);
        // 如果需要显示消息以及数据,则需要正常处理或进行脱敏
        return $sensitive?$this->sanitize($this->errors):$this->errors;
    }

}