<?php

namespace base\Database\Execution;

use base\Database\Result\ResultInterface;
use base\Database\Sql\Compiler\CompiledStatementInterface;

/**
 * SQL 执行器接口
 */
interface SqlExecutorInterface {

    /**
     * 执行一条 SQL
     * 
     * @param CompiledStatementInterface $statement 编译后的 SQL 语句对象
     * @return ResultInterface
     */
    public function execute(CompiledStatementInterface $statement): ResultInterface;

}