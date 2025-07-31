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

    public function __construct(array $data,$rules=[]) {
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
     * @return bool
     */
    public function validate(array $data=[]): bool {
        if(empty($data))
            $data=$this->data;
        foreach($this->rules as $field=>$rules) {
            $value=$data[$field]??null;
            $ruleList=is_array($rules)?$rules:explode('|',$rules);
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
     * 获取验证错误信息
     * 
     * @return array
     */
    public function errors(): array {
        return $this->errors;
    }
}