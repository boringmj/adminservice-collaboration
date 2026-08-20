<?php

namespace base\Database\Exception;

/**
 * SQL 编译异常
 *
 * - 语句定义无法被编译器处理
 * - 如: 不支持的语句定义/语句类型、方言不支持内联参数、无法转义的值类型
 */
class CompilerException extends DatabaseException {

}
