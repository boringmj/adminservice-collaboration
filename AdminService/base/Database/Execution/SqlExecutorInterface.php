<?php

namespace base\Database\Execution;

use base\Database\Result\ResultInterface;
use base\Database\Compiler\CompiledQueryInterface;

/**
 * SQL 执行器接口
 */
interface SqlExecutorInterface {

    /**
     * 执行一条 SQL
     * 
     * @param CompiledQueryInterface $query 编译后的查询对象
     * @return ResultInterface
     */
    public function execute(CompiledQueryInterface $query): ResultInterface;

}