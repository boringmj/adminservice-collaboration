<?php

namespace base\Database\Coordinator;

use base\Database\Sql\BuilderInterface;
use base\Database\Result\ResultInterface;

/**
 * 查询协调器接口
 * 
 * - 负责调度策略、编译构造器和连接资源
 * - 连接资源应该由连接管理器分配, 执行器不应该直接管理和持有连接资源
 */
interface QueryCoordinatorInterface {

    /**
     * 协调查询
     * @access public
     * @param BuilderInterface $builder 查询构建器
     * @return ResultInterface
     */
    public function query(
        BuilderInterface $builder,
    ): ResultInterface;

}