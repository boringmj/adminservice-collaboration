<?php

namespace base\Database;

use base\Database\Sql\BuilderInterface;

/**
 * 查询执行器接口
 * 
 * - 这个接口用于执行SQL查询，并统一处理查询结果与异常
 * - 这个接口的实现类应该在构造函数中至少传入一个数据库连接对象和驱动器对象
 * - 这个接口的实现层不应该直接暴露给上层业务逻辑
 */
interface QueryExecutorInterface {

    /**
     * 执行SQL查询
     * 
     * @access public
     * @param BuilderInterface $builder 查询构建器
     * @param array $params 查询参数
     * @return ResultInterface
     */
    public function query(
        BuilderInterface $builder,
        array $params=[]
    ): ResultInterface;

}