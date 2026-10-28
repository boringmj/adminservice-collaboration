<?php

namespace base\Attribute;

use Attribute;

/**
 * 自动生命周期方法
 *
 * - 标记的方法在对象完成属性 / Setter 注入后自动调用
 * - 方法参数按类型自动注入(与构造函数注入一致)
 * - 形参上的 `#[Config]` 同样生效
 * - 不支持 Proxy / Deferred; 需要延迟能力请使用真正的依赖注入模式
 *
 * 装配实现在 `AdminService\Autowire`(见 `autowireMethod()`), 契约层不引用实现层。
 */
#[Attribute(Attribute::TARGET_METHOD)]
final class AutowireLifecycle {

    /**
     * 构造方法
     *
     * 无参数, 避免与带模式的依赖注入注解混淆。
     */
    public function __construct() { }

}
