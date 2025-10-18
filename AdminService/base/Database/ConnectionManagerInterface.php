<?php

namespace base\Database;

use \Closure;
use base\Database\ConfigInterface as Config;
use base\Database\ConnectionInterface as Connection;
use base\Database\AbstractConnectionPool as ConnectionPool;

/**
 * 数据库连接管理器接口
 */
interface ConnectionManagerInterface {

    /**
     * 普通连接(可复用)
     * @var int
     */
    public const NORMAL_CONNECTION=1;

    /**
     * 不可复用连接
     * @var int
     */
    public const UNREUSABLE_CONNECTION=2;
    
    /**
     * 事务连接(调度管理,空闲连接可复用)
     * @var int
     */
    public const TRANSACTION_CONNECTION=3;

    /**
     * 构造函数
     * 
     * @param array<string,Config> $configs 数据库连接配置
     * @param string $default 默认连接名称
     * @param ConnectionPool|null $normalConnections 已存在的可复用连接池
     * @param ConnectionPool|null $unreusableConnections 已存在的不可复用连接池
     * @param array<string,ConnectionPool>|null $transactionConnections 已存在的事务连接池
     */
    public function __construct(
        array $configs,
        string $default='default',
        ?ConnectionPool $normalConnections=null,
        ?ConnectionPool $unreusableConnections=null,
        ?array $transactionConnections=null
    );

    /**
     * 注册自动销毁
     * 
     * @return void
     */
    public function registerAutoDestroy(): void;

    /**
     * 获取数据库连接实例
     * 
     *  - 从可复用连接池获取连接实例, 如果尚未创建, 则新建连接并缓存到连接池中
     *  - 默认连接名称由 `self::defaultConnectionName` 属性指定, 默认为 `default`
     *  - 如果不启用 `PDO::ATTR_PERSISTENT` 则连接的生命周期为请求周期
     *  - 如果启用 `PDO::ATTR_PERSISTENT` 则连接的生命周期由 `PHP-FPM` 进程决定
     *  - 注意, 无论是否启用 `PDO::ATTR_PERSISTENT`, 请求结束时连接池都会被释放
     * 
     * @param string|null $name 数据库连接实例名称
     * @return Connection 数据库连接实例
     */
    public function get(?string $name=null): Connection;

    /**
     * 新建数据库连接实例
     * 
     *  - 根据配置文件新建数据库连接实例
     *  - 无论是否可复用都会创建新的连接实例
     * 
     * @param string|null $name 数据库配置名称
     * @param int $type 连接类型
     *  - 如果为 `self::NORMAL_CONNECTION`, 则创建可复用连接实例
     *  - 如果为 `self::UNREUSABLE_CONNECTION`, 则创建不可复用连接实例
     *  - 如果为 `self::TRANSACTION_CONNECTION`, 则创建事务连接实例
     *  - 非法值默认为不可复用连接实例
     *  - 不同类型的连接实例由不同的连接池缓存
     * @return Connection 数据库连接实例
     */
    public function create(
        ?string $name=null,
        int $type=self::UNREUSABLE_CONNECTION
    ): Connection;

    /**
     * 创建一次性连接实例(建议优先考虑使用不可复用连接)
     * @template T
     * @param Closure(Connection): T $callback 回调函数
     *  - 回调函数仅接受一个参数, 即连接实例({@see \base\Database\AbstractConnection})
     * @param string|null $name 数据库配置名称
     * @param Closure|null $onCloseError 连接关闭时发生的错误的回调
     *  - 回调函数仅接受一个参数, 即异常对象({@see \PDOException})
     *  - 如果为 `null`, 则不执行回调
     * @return T 回调函数返回值
     */
    public function createTemporaryConnection(
        Closure $callback,
        ?string $name=null,
        ?Closure $onCloseError=null
    ): mixed;

    /**
     * 将事务连接存储到连接池
     * 
     * @param string $name 连接池名称
     * @param Connection $connection 连接实例
     * @return void
     */
    public function setTransactionalConnection(
        string $name,
        Connection $connection
    ): void;

    /**
     * 从事务连接池获取一个闲置的事务连接实例
     * 
     *  - 如果没有闲置的事务连接实例, 则新建一个事务连接实例
     *  - 如果有闲置的事务连接实例, 则从闲置的事务连接实例中获取一个
     * 
     * @param string|null $name 数据库配置名称
     * @return Connection 事务连接实例
     */
    public function getIdleTransactionalConnection(
        ?string $name=null,
    ): Connection;

    /**
     * 销毁不可复用的连接实例
     * 
     * @param int $connectionId 连接实例标识
     * @return void
     */
    public function destroyUnreusableConnections(int $connectionId): void;

    /**
     * 销毁所有连接实例
     * 
     * @return void
     */
    public function destroyAllConnections(): void;

}