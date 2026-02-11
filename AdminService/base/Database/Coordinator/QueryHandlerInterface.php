<?php

namespace base\Database\Coordinator;

use base\Database\Result\ResultInterface;

/**
 * 查询处理器接口
 */
interface QueryHandlerInterface {

    /**
     * 处理查询
     * @param QueryContextInterface $query 查询上下文
     * @return ResultInterface
     */
    public function handle(QueryContextInterface $query): ResultInterface;

}