<?php

namespace base\Database\Connection;

/**
 * 连接实例接口
 * 
 * - 该接口本身是资源管理器, 不应该参与业务逻辑与生命周期管理
 * - 连接的生命周期应该由资源管理器管理
 * - 执行能力应该由执行器/驱动层提供
 * 
 * @template T
 */
interface ConnectionInterface {

    /**
     * 获取连接的数据库驱动名称
     * @return string
     */
    public function getDriver(): string;

    /**
     * 获取连接对象
     * @return T
     */
    public function getConnection();

}