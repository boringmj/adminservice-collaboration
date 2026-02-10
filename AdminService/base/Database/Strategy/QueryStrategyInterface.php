<?php

namespace base\Database\Strategy;

use base\Database\Result\ResultInterface;
use base\Database\Coordinator\QueryContextInterface;


/**
 * 查询策略接口
 * 
 * - 承载查询执行逻辑以及高级查询功能
 * - 例如: 缓存、重试或读写分离
 * - 不应该持有连接相关状态和管理连接
 */
interface QueryStrategyInterface {

    /**
     * 执行查询
     * @access public
     * @param QueryContextInterface $context 查询上下文对象
     * @return ResultInterface
     */
    public function execute(
        QueryContextInterface $context,
    ): ResultInterface;

}