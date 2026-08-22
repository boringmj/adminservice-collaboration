<?php

namespace base\Database\Connection;

use function array_pop;
use function count;

/**
 * PDO 连接池
 *
 * - 通过会话工厂创建连接, 空闲会话在归还时清理后复用
 * - 会话持有对连接池的引用, 释放时自动回到连接池
 * - 有界: 闲置会话超过上限直接丢弃
 * - 脏会话(查询失败或修改过会话状态)归还时丢弃, 不复用, 避免污染其他使用者
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
     * 闲置会话上限(超过则丢弃, 控制物理连接数)
     * @var int
     */
    private int $maxIdle;

    /**
     * 构造方法
     *
     * @access public
     * @param callable $sessionFactory 会话工厂(callable(): PdoConnectionSession)
     * @param int $maxIdle 闲置会话上限
     */
    public function __construct(callable $sessionFactory,int $maxIdle=20) {
        $this->sessionFactory=$sessionFactory;
        $this->maxIdle=$maxIdle;
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
     * 将连接归还到连接池(契约入口, 会话释放时回调本方法)
     *
     * - 归属校验: 仅接受本池借出的会话, 防止跨池归还污染
     * - 幂等: 已归还的会话忽略, 防止重复入池(同一 PDO 被借出两次)
     * - 回滚残留事务(会话变量/临时表等 PDO 无法重置, 由上层约定规避)
     * - 脏会话或超过闲置上限: 丢弃不复用(对象失引用后由 GC 释放连接)
     *
     * @access public
     * @param ConnectionSessionInterface $connection 数据库连接会话实例
     * @return void
     */
    public function release(ConnectionSessionInterface $connection): void {
        // 归属校验: 只归还本池借出的会话
        if(!$connection instanceof PdoConnectionSession||$connection->getPool()!==$this)
            return;
        // 幂等: 已归还的会话忽略, 标记释放供会话 release() 守卫配合
        if($connection->isReleased())
            return;
        $connection->markReleased();
        $connection->reset();
        // 脏会话或超过闲置上限: 丢弃不复用(对象失引用后由 GC 释放连接)
        if($connection->isDirty()||count($this->idle)>=$this->maxIdle)
            return;
        $this->idle[]=$connection;
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

}
