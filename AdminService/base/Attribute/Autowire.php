<?php

namespace base\Attribute;

/**
 * 自动注入基类
 */
class Autowire {

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
     * @param bool $proxy 是否注入动态代理类
     *  - 需要`$name`参数不为`null`
     *  - 需要属性未声明类型,或类型为 {@see \AdminService\DynamicProxy}
     *  - 否则此参数无效
     */
    public function __construct(?string $name=null,bool $proxy=false) {
        $this->name=$name;
        $this->proxy=$proxy;
    }

    /**
     * 获取依赖的类名
     * 
     * @access public
     * @return string|null
     */
    public function getName(): ?string {
        return $this->name;
    }

    /**
     * 获取是否注入动态代理类
     * 
     * @access public
     * @return bool
     */
    public function getProxy(): bool {
        return $this->proxy;
    }

}