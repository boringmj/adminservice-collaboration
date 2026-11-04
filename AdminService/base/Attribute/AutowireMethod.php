<?php

namespace base\Attribute;

use Attribute;

/**
 * 单依赖方法注入
 *
 * - 必须指定 `name`; 方法必须只有一个形参, 注入该显式依赖
 * - 支持 `AutowireMode::Normal` / `Proxy` / `Deferred`
 * - 生命周期钩子请使用 {@see AutowireLifecycle}
 *
 * 装配实现在 `AdminService\Autowire`(见 `autowireMethod()`), 契约层不引用实现层。
 */
#[Attribute(Attribute::TARGET_METHOD)]
class AutowireMethod extends Autowire { }
