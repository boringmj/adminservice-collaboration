<?php

namespace base\Database\Exception;

/**
 * 执行异常
 *
 * - SQL 执行与结果集处理相关错误
 * - 预留, 由执行层(SqlExecutor/ResultProcessor)实现时抛出
 */
class ExecutionException extends DatabaseException {

}
