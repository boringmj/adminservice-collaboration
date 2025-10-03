<?php

namespace AdminService\Autowire;

use \Attribute;
use base\Attribute\Autowire;

/**
 * 自动注入属性(只能注入类,支持别名和绑定,支持抽象类和接口)
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
class AutowireProperty extends Autowire { }