<?php

namespace base\Attribute;

use Attribute;

/**
 * 自动注入属性(只能注入类, 支持别名和绑定, 支持抽象类和接口)
 *
 * 模式通过第二个参数指定:
 * - `#[AutowireProperty(A::class)]`: 直接注入真实对象
 * - `#[AutowireProperty(A::class, mode: AutowireMode::Proxy)]`: 注入动态代理
 *   (声明类型必须是 `DynamicProxy` / `mixed` / `object` / 无类型, 或包含 `DynamicProxy` 的联合类型;
 *   具体类型 `A` 与交叉类型 `A&DynamicProxy` 均会报错)
 * - `#[AutowireProperty(A::class, mode: AutowireMode::Deferred)]`: 根对象完成装配后注入
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
class AutowireProperty extends Autowire { }
