<?php

namespace base\Database;

/**
 * 数据库连接池工厂接口
 */
interface ConnectionPoolFactoryInterface {

    /**
     * 创建连接池
     * @return AbstractConnectionPool
     */
    public function create(): AbstractConnectionPool;

}