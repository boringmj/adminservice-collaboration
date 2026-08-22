<?php

namespace base\Database\Coordinator;

use base\Database\Connection\ConnectionSessionInterface;
use base\Database\Result\ResultInterface;
use base\Database\Sql\Builder\StatementBuilderInterface;
use base\Database\Sql\Compiler\CompiledStatementInterface;

/**
 * 查询核心协调器接口
 *
 * - 负责调度中间件、构造器和连接资源
 * - 连接资源应该由连接管理器分配, 执行器不应该直接管理和持有连接资源
 * - $session 指定时(事务)复用该会话, 否则由连接管理器从池分配
 */
interface QueryCoordinatorInterface {

    /**
     * 协调查询
     * @access public
     * @param StatementBuilderInterface $builder SQL语句构建器
     * @param ConnectionSessionInterface|null $session 指定会话(事务)时为 null 则走连接池
     * @return ResultInterface
     */
    public function query(
        StatementBuilderInterface $builder,
        ?ConnectionSessionInterface $session=null,
    ): ResultInterface;

    /**
     * 执行已编译的原生语句
     * @access public
     * @param CompiledStatementInterface $statement 已编译语句
     * @param ConnectionSessionInterface|null $session 指定会话(事务)时为 null 则走连接池
     * @param bool $markDirtyOnSessionModify 原生 SQL 是否修改会话状态
     * @return ResultInterface
     */
    public function raw(
        CompiledStatementInterface $statement,
        ?ConnectionSessionInterface $session=null,
        bool $markDirtyOnSessionModify=false,
    ): ResultInterface;

}