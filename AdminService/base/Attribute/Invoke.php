<?php

namespace base\Attribute;

/**
 * 方法或函数标记基类
 */
abstract class Invoke {

    /**
     * 依赖的类名
     * @var string|array|null
     */
    protected string|array|null $name;

    /**
     * 是否注入动态代理类
     * @var bool
     */
    protected bool $proxy;

    /**
     * @param string|array|null $name 依赖的类名
     * @param bool $proxy 是否注入动态代理类
     *  - 需要形参未声明类型,或类型为 {@see \AdminService\DynamicProxy}
     *  - 仅在标记中显示指定`$name`参数时有效
     *  - 不符合条件时,本参数无效
     */
    public function __construct(string|array|null $name=null,bool $proxy=false) {
        $this->name=$name;
        $this->proxy=$proxy;
    }

    public function getName(): string|array|null {
        return $this->name;
    }

    public function getProxy(): bool {
        return $this->proxy;
    }

}