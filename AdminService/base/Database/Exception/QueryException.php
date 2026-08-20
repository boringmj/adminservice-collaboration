<?php

namespace base\Database\Exception;

/**
 * 查询对象异常
 *
 * - 查询模型的构建与语义校验错误
 * - 如: 非法字段/表名、非法操作符、关联条件错误、排序/限制/锁错误、更新/删除缺少条件、写入数据缺失
 */
class QueryException extends DatabaseException {

}
