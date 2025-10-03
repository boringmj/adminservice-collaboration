<?php

namespace AdminService\Autowire;

use \Attribute;
use \base\Attribute\Parameter;

/**
 * 构造后自动运行方法(只能注入类,支持别名和绑定,支持抽象类和接口)
 */
#[Attribute(Attribute::TARGET_METHOD)]
class PostConstruct extends Parameter { }