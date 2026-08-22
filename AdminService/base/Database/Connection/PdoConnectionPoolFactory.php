<?php

namespace base\Database\Connection;

/**
 * PDO 连接池工厂
 *
 * - 持有会话工厂与闲置上限, 每次创建返回新的连接池
 */
final class PdoConnectionPoolFactory implements ConnectionPoolFactoryInterface {

    /**
     * 会话工厂
     * @var callable
     */
    private $sessionFactory;

    /**
     * 闲置会话上限
     * @var int
     */
    private int $maxIdle;

    /**
     * 构造方法
     *
     * @access public
     * @param callable $sessionFactory 会话工厂(callable(): PdoConnectionSession)
     * @param int $maxIdle 闲置会话上限(超出丢弃, 控制物理连接数)
     */
    public function __construct(callable $sessionFactory,int $maxIdle=20) {
        $this->sessionFactory=$sessionFactory;
        $this->maxIdle=$maxIdle;
    }

    /**
     * 创建连接池
     *
     * @access public
     * @return ConnectionPoolInterface
     */
    public function create(): ConnectionPoolInterface {
        return new PdoConnectionPool($this->sessionFactory,$this->maxIdle);
    }

}
