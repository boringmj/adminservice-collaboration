<?php

namespace base\Attribute;

/**
 * 自动注入基类
 *
 * - `$name`: 显式依赖类名; `null` 表示按目标类型解析
 * - `$mode`: 注入模式; 默认直接注入真实对象
 * - `bool` 第二参数仅用于旧写法兼容: `true` 等同 `AutowireMode::Proxy`,
 *   `false` 等同 `AutowireMode::Normal`; 新代码请使用 `mode:`
 */
class Autowire {

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
     * @param AutowireMode|bool $mode 注入模式; `bool` 为旧写法兼容
     * @param ?bool $proxy 旧写法的具名参数兼容; 不要与新代码的 `mode` 混用
     *  - `Proxy` 需要 `$name` 不为 `null`
     *  - `Proxy` 需要属性/形参未声明类型, 或类型包含 `DynamicProxy`
     *  - `Deferred` 在根对象完成属性 / Setter / 生命周期装配后解析
     */
    public function __construct(
        ?string $name=null,
        AutowireMode|bool $mode=AutowireMode::Normal,
        ?bool $proxy=null
    ) {
        if($proxy!==null) {
            if($mode!==AutowireMode::Normal&&$mode!==false)
                throw new \InvalidArgumentException(
                    'Autowire mode and proxy cannot be specified at the same time.'
                );
            $mode=$proxy;
        }
        $this->name=$name;
        $this->mode=$mode instanceof AutowireMode
            ?$mode
            :($mode?AutowireMode::Proxy:AutowireMode::Normal);
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

    /**
     * 是否注入动态代理
     *
     * @access public
     * @deprecated 使用 {@see getMode()} 判断 `AutowireMode::Proxy`
     * @return bool
     */
    public function getProxy(): bool {
        return $this->mode===AutowireMode::Proxy;
    }

}
