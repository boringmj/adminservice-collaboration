<?php

namespace base\Database\Exception;

/**
 * 连接异常
 *
 * - 连接建立、连接池、连接会话相关错误
 * - 预留, 由连接层(ConnectionManager/ConnectionPool/ConnectionSession)实现时抛出
 */
class ConnectionException extends DatabaseException {

}
