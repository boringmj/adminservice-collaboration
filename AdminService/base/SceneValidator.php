<?php

namespace base;

abstract class SceneValidator extends Validator {

    /**
     * 当前验证场景
     * @var string|null
     */
    protected ?string $scene=null;

    /**
     * 所有场景的字段映射表
     * @var array
     */
    protected array $scenes=[];

    public function __construct(array $data=[],$rules=[]) {
        parent::__construct($data,$rules);
        $this->scenes=$this->scenes();
    }

    /**
     * 设置当前验证场景(为`null`则不限定场景)
     * 
     * @param ?string $scene 场景名
     * @return self
     */
    public function scene(?string $scene=null): self {
        $this->scene=$scene;
        return $this;
    }

    /**
     * 获取当前场景的规则
     * 
     * @return array
     */
    protected function getSceneRules(): array {
        if($this->scene===null)
            return $this->rules;
        $sceneFields=$this->scenes[$this->scene]??[];
        $sceneRules=[];
        foreach($sceneFields as $field) {
            if(isset($this->rules[$field])) {
                $sceneRules[$field]=$this->rules[$field];
            }
        }
        return $sceneRules;
    }

    /**
     * 验证数据
     * 
     * @param array $data 待验证的数据
     * @param array $rules 外部传入的规则(与默认规则合并,冲突则使用外部规则)
     * @return bool
     */
    public function validate(array $data=[],array $rules=[]): bool {
        if(!empty($data))
            $this->data=$data;
        // 合并默认和外部规则
        $this->rules=array_merge($this->rules,$rules);
        $this->errors=[];
        $rulesToApply=$this->getSceneRules();
        // 验证并重置场景
        return $this->scene()->doValidate($this->data,$rulesToApply);
    }

    /**
     * 所有场景字段映射
     * 
     * @return array
     */
    protected function scenes(): array {
        return $this->scenes;
    }

    /**
     * 通过字段名取数据值
     * 
     * @param string $field 字段名
     * @param mixed $value 默认值
     * @return mixed
     */
    protected function data(string $field,mixed $value=null) {
        return $this->data[$field]??$value;
    }

}
