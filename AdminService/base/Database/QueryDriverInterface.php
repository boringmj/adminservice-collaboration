<?php

namespace base\Database;

use base\Database\Sql\BuilderInterface;

/**
 * 查询驱动接口
 * 
 * @access public
 * @package base\Database
 * @version 1.1.0
 */
 interface QueryDriverInterface {

    /**
     * 执行一条 SQL
     * 
     * @access public
     * @param AbstractConnection $connection 数据库连接对象
     * @param BuilderInterface $builder SQL构建器对象
     * @param array $params 查询参数
     * @return ResultInterface
     */
    public function execute(
        AbstractConnection $connection,
        BuilderInterface $builder,
        array $params=[]
    ): ResultInterface;

}