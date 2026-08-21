<?php

namespace AdminService\Autowire;

use Attribute;

/**
 * 自动注入方法(生命周期钩子)
 *
 * - 标记的方法在对象构建并注入属性/Setter 后自动调用
 * - 方法参数按类型自动注入(支持别名/绑定/抽象类/接口), 与构造函数注入一致
 * - 无类型且无默认值的参数会抛 AutowireException
 *
 * @see \base\Container::autowireMethod()
 */
#[Attribute(Attribute::TARGET_METHOD)]
class AutowireMethod {

}
