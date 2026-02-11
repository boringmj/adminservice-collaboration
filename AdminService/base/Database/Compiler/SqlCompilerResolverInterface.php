<?php

namespace base\Database\Sql;

use base\Database\Compiler\SqlCompilerInterface;
use base\Database\Connection\ConnectionSessionInterface;

/**
 * SQL编译器解析器接口
 */
interface SqlCompilerResolverInterface {

    /**
     * 解析SQL编译器
     * @param ConnectionSessionInterface $connection 连接会话
     * @return SqlCompilerInterface
     */
    public function resolve(ConnectionSessionInterface $connection): SqlCompilerInterface;

}