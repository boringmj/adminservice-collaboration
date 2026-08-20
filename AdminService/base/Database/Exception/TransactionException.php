<?php

namespace base\Database\Exception;

/**
 * 事务异常
 *
 * - 事务状态与事务执行器相关错误
 * - 预留, 由事务层(TransactionExecutor/NestedTransactionExecutor)实现时抛出
 */
class TransactionException extends DatabaseException {

}
