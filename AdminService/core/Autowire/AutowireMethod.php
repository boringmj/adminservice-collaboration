<?php

namespace AdminService\Autowire;

use Attribute;
use base\Attribute\Autowire;

/**
 * 自动注入方法(生命周期钩子)
 *
 * - 标记的方法在对象构建并注入属性/Setter 后自动调用
 * - 不指定 name: 方法所有参数按类型自动注入(与构造函数注入一致)
 * - 指定 name: 方法必须只有单个参数, 注入该显式依赖(支持 proxy), 与 Setter 注入一致
 * - 无类型且无默认值的参数会抛 AutowireException
 *
 * @see \base\Container::autowireMethod()
 */
#[Attribute(Attribute::TARGET_METHOD)]
class AutowireMethod extends Autowire {

}
