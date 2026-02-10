<?php

namespace base\Database\Connection;

/**
 * 数据库连接管理器抽象类
 */
interface ConnectionManagerInterface {

    /**
     * 获取一个可复用的连接会话实例
     * @return ConnectionSessionInterface 数据库连接会话实例
     */
    public function getConnection(): ConnectionSessionInterface;

    /**
     * 获取一个独占的连接会话实例
     * @return ConnectionSessionInterface 数据库连接会话实例
     */
    public function getExclusiveConnection(): ConnectionSessionInterface;

}