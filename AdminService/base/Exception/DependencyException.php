<?php

namespace base\Exception;

use base\Exception;

/**
 * 依赖未就绪异常(契约层)
 *
 * - 场景: 契约层的对象没有按预期拿到依赖 —— 例如控制器/路由不是由容器构建、缺少请求对象或配置契约
 * - 为什么需要它: `base\Exception` 是**抽象**的(只作契约), 契约层不能抛实现层的 `AdminService\Exception`,
 *   因此提供一个契约层的具体异常
 *
 * @access public
 * @package base\Exception
 * @version 1.0.0
 */
class DependencyException extends Exception {
}
