<?php

namespace base\Database\Execution;

use base\Database\Result\ResultInterface;
use base\Database\Coordinator\QueryContextInterface;


/**
 * 查询执行器接口
 * 
 * - 仅能承载查询执行逻辑
 * - 需保持自身无状态
 */
interface QueryExecutorInterface {

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