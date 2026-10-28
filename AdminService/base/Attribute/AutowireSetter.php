<?php

namespace base\Attribute;

use Attribute;

/**
 * 自动注入 Setter 方法(形参必需且只能是一个类名或接口名, 支持别名和绑定, 支持抽象类和接口)
 *
 * 模式通过第二个参数指定:
 * - `#[AutowireSetter(A::class)]`: 直接注入真实对象
 * - `#[AutowireSetter(A::class, mode: AutowireMode::Proxy)]`: 注入动态代理
 * - `#[AutowireSetter(A::class, mode: AutowireMode::Deferred)]`: 根对象完成装配后调用 Setter
 */
#[Attribute(Attribute::TARGET_METHOD)]
class AutowireSetter extends Autowire { }
