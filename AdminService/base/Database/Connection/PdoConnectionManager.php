<?php

namespace base\Database\Connection;

/**
 * PDO 连接管理器
 *
 * - 共享连接从连接池获取, 可复用
 * - 独占连接直接新建, 不进入连接池
 */
final class PdoConnectionManager implements ConnectionManagerInterface {

    /**
     * 共享连接池
     * @var ConnectionPoolInterface
     */
    private ConnectionPoolInterface $pool;

    /**
     * 独占会话工厂
     * @var callable
     */
    private $sessionFactory;

    /**
     * 构造方法
     *
     * @access public
     * @param ConnectionPoolInterface $pool 共享连接池
     * @param callable $sessionFactory 独占会话工厂(callable(): PdoConnectionSession)
     */
    public function __construct(ConnectionPoolInterface $pool,callable $sessionFactory) {
        $this->pool=$pool;
        $this->sessionFactory=$sessionFactory;
    }

    /**
     * 获取一个可复用的连接会话实例
     *
     * @access public
     * @return ConnectionSessionInterface
     */
    public function getConnection(): ConnectionSessionInterface {
        return $this->pool->acquire();
    }

    /**
     * 获取一个独占的连接会话实例
     *
     * @access public
     * @return ConnectionSessionInterface
     */
    public function getExclusiveConnection(): ConnectionSessionInterface {
        return ($this->sessionFactory)();
    }

}
