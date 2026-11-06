<?php

namespace base\Attribute;

/**
 * 自动注入基类
 *
 * - `$name`: 显式依赖类名; `null` 表示按目标类型解析
 * - `$mode`: 注入模式; 默认直接注入真实对象
 * - 本类不是注解, 不直接实例化
 */
abstract class Autowire {

    /**
     * 依赖的类名
     * @var ?string
     */
    protected ?string $name;

    /**
     * 注入模式
     * @var AutowireMode
     */
    protected AutowireMode $mode;

    /**
     * @param ?string $name 依赖的类名
     * @param AutowireMode $mode 注入模式
     *  - `Proxy` 需要 `$name` 不为 `null`
     *  - `Proxy` 需要属性/形参未声明类型, 或类型包含 `DynamicProxy`
     *  - `Deferred` 在根对象完成属性 / Setter / 生命周期装配后解析
     */
    public function __construct(
        ?string $name=null,
        AutowireMode $mode=AutowireMode::Normal
    ) {
        $this->name=$name;
        $this->mode=$mode;
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
     * 获取注入模式
     *
     * @access public
     * @return AutowireMode
     */
    public function getMode(): AutowireMode {
        return $this->mode;
    }

}
