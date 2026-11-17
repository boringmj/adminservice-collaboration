<?php

namespace base\Database\Exception;

use base\Exception as BaseException;

/**
 * 数据库抽象层异常基类
 *
 * - 新 DBAL 的异常统一继承该类, 融入框架异常体系(可通过 base\Exception 捕获)
 * - 不依赖应用容器与日志写入, 便于作为独立库复用与单元测试
 * - 请使用具体子类抛错, 不要直接抛出该类
 */
abstract class DatabaseException extends BaseException {

}
