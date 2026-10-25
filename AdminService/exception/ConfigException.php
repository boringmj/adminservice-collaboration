<?php

namespace AdminService\exception;

use base\Exception as BaseException;

/**
 * 配置异常类(配置装配期可抛)
 *
 * - 不能继承 `AdminService\Exception`: 其构造方法读取配置, 配置装配期尚未就绪, 会触发二次故障。
 *   本类直接继承抽象契约 `base\Exception`, 不触碰配置和容器。
 * - 与 `base\Database\Exception\ConfigException` 同名不同域: 那个只服务数据库层的连接/方言/编译器配置。
 * - 错误码段位 `1009xx`(配置模块)。
 *
 * @access public
 * @package AdminService\exception
 * @version 1.0.0
 */
class ConfigException extends BaseException {
}
