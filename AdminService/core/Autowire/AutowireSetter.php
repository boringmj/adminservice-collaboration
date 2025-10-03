<?php

namespace AdminService\Autowire;

use \Attribute;
use \base\Attribute\Autowire;

/**
 * 自动注入Setter方法(形参必需且只能是一个类名或接口名,支持别名和绑定,支持抽象类和接口)
 */
#[Attribute(Attribute::TARGET_METHOD)]
class AutowireSetter extends Autowire { }