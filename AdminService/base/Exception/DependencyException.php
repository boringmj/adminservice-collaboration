<?php

namespace base\Exception;

use base\Exception;

/**
 * 依赖未就绪异常(契约层)
 *
 * - 场景: 契约层的对象没有按预期拿到依赖 —— 例如控制器/路由不是由容器构建、缺少请求对象或配置契约
 * - 契约层不能抛实现层异常, 因此提供本类; `base\Exception` 仍只作抽象契约。
 *
 * @access public
 * @package base\Exception
 * @version 1.0.0
 */
class DependencyException extends Exception {
}
