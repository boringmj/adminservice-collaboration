<?php

namespace base\Database\Coordinator;

use base\Database\Result\ResultInterface;
use base\Database\Sql\Builder\StatementBuilderInterface;

/**
 * 查询核心协调器接口
 * 
 * - 负责调度中间件、构造器和连接资源
 * - 连接资源应该由连接管理器分配, 执行器不应该直接管理和持有连接资源
 */
interface QueryCoordinatorInterface {

    /**
     * 协调查询
     * @access public
     * @param StatementBuilderInterface $builder SQL语句构建器
     * @return ResultInterface
     */
    public function query(
        StatementBuilderInterface $builder,
    ): ResultInterface;

}