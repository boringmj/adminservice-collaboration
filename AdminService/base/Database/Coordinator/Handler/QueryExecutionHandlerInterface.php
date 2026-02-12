<?php

namespace base\Database\Coordinator\Handler;

use base\Database\Result\ResultInterface;
use base\Database\Execution\SqlExecutorInterface;
use base\Database\Coordinator\QueryContextInterface;


/**
 * 查询执行处理器接口
 * 
 * - 负责执行查询
 * - 分担中心协调器调度执行器的职责
 */
interface QueryExecutionHandlerInterface {

    /**
     * 执行查询
     * @access public
     * @param SqlExecutorInterface $executor SQL 执行器对象
     * @param QueryContextInterface $context 查询上下文对象
     * @return ResultInterface
     */
    public function execute(
        SqlExecutorInterface $executor,
        QueryContextInterface $context
    ): ResultInterface;
}