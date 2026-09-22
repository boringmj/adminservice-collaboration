<?php

namespace AdminService\exception;

use base\Exception as BaseException;

/**
 * 配置异常类(配置装配期可抛)
 *
 * - **为什么不继承 `AdminService\Exception`**: 那个的构造方法会读 `Config::get('app.debug')` 决定是否写日志,
 *   而本异常恰恰是**在配置装配期**抛出的 —— 那时配置还没就绪(首次引导时 `Config::$configs` 尚未赋值),
 *   于是"配置写错"会退化成"二次故障", 报错信息反而查不到真正的原因。
 *   故这里直接继承抽象契约 `base\Exception`, 不触碰配置、不触碰容器。
 * - 与 `base\Database\Exception\ConfigException` 同名不同域: 那个只服务数据库层的连接/方言/编译器配置。
 * - 错误码段位 `1009xx`(配置模块)。
 *
 * @access public
 * @package AdminService\exception
 * @version 1.0.0
 */
class ConfigException extends BaseException {
}
