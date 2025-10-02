<?php

namespace AdminService;

use \Attribute;

/**
 * 属性自动注入(只能注入类,支持别名和绑定,支持抽象类和接口)
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
class AutowireProperty {

    /**
     * 依赖的类名
     * @var ?string
     */
    protected ?string $name;

    /**
     * 是否注入动态代理类
     * @var bool
     */
    protected bool $proxy;

    /**
     * @param ?string $name 依赖的类名
     * @param bool $proxy 是否注入动态代理类(需字段没有显示声明类型,否则本属性无效)
     */
    public function __construct(?string $name=null,bool $proxy=false) {
        $this->name=$name;
        $this->proxy=$proxy;
    }

    public function getName(): ?string {
        return $this->name;
    }

    public function getProxy(): bool {
        return $this->proxy;
    }

}