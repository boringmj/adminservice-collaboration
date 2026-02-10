<?php

namespace base\Database\Connection;

/**
 * 数据库连接池工厂接口
 */
interface ConnectionPoolFactoryInterface {

    /**
     * 创建连接池
     * @return ConnectionPoolInterface
     */
    public function create(): ConnectionPoolInterface;

}