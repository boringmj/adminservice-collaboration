<?php

namespace base\Database\Connection;

/**
 * PDO 连接池工厂
 *
 * - 持有会话工厂, 每次创建返回新的连接池
 */
final class PdoConnectionPoolFactory implements ConnectionPoolFactoryInterface {

    /**
     * 会话工厂
     * @var callable
     */
    private $sessionFactory;

    /**
     * 构造方法
     *
     * @access public
     * @param callable $sessionFactory 会话工厂(callable(): PdoConnectionSession)
     */
    public function __construct(callable $sessionFactory) {
        $this->sessionFactory=$sessionFactory;
    }

    /**
     * 创建连接池
     *
     * @access public
     * @return ConnectionPoolInterface
     */
    public function create(): ConnectionPoolInterface {
        return new PdoConnectionPool($this->sessionFactory);
    }

}
