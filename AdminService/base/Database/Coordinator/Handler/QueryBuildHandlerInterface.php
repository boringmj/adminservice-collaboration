<?php

namespace base\Database\Coordinator\Handler;

use base\Database\Connection\ConnectionSessionInterface;
use base\Database\Sql\Builder\StatementBuilderInterface;
use base\Database\Sql\Compiler\CompiledStatementInterface;

/**
 * 查询构建处理器接口
 * 
 * - 作为查询构建器与数据库连接之间的桥梁
 * - 分担中心协调器的查询构建工作
 */
interface QueryBuildHandlerInterface {

    /**
     * 执行查询构建器
     * @param ConnectionSessionInterface $connection 连接会话对象
     * @param StatementBuilderInterface $builder 查询构建器对象
     * @return CompiledStatementInterface
     */
    public function execute(
        ConnectionSessionInterface $connection,
        StatementBuilderInterface $builder
    ): CompiledStatementInterface;

}