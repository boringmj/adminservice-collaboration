<?php

namespace base\Database\Connection;

use function array_pop;

/**
 * PDO 连接池
 *
 * - 通过会话工厂创建连接, 空闲会话在归还时清理后复用
 * - 会话持有对连接池的引用, 释放时自动回到连接池
 */
final class PdoConnectionPool implements ConnectionPoolInterface {

    /**
     * 会话工厂
     * @var callable
     */
    private $sessionFactory;

    /**
     * 空闲会话列表
     * @var PdoConnectionSession[]
     */
    private array $idle=array();

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
     * 从连接池获取一个连接
     *
     * @access public
     * @return ConnectionSessionInterface
     */
    public function acquire(): ConnectionSessionInterface {
        $session=array_pop($this->idle);
        if($session===null)
            $session=($this->sessionFactory)();
        $session->setPool($this);
        $session->checkout();
        return $session;
    }

    /**
     * 将连接归还到连接池
     *
     * @access public
     * @param ConnectionSessionInterface $connection 数据库连接会话实例
     * @return void
     */
    public function release(ConnectionSessionInterface $connection): void {
        $connection->release();
    }

    /**
     * 关闭并销毁连接池中的所有连接
     *
     * @access public
     * @return void
     */
    public function close(): void {
        $this->idle=array();
    }

    /**
     * 归还会话到连接池(由会话释放时回调)
     *
     * @access public
     * @param PdoConnectionSession $session 连接会话
     * @return void
     */
    public function returnToPool(PdoConnectionSession $session): void {
        // 清理连接以保证连接干净
        $session->reset();
        $this->idle[]=$session;
    }

}
