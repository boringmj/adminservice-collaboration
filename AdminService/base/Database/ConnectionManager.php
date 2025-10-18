<?php

namespace base\Database;

use \PDO;
use \Closure;
use \Throwable;
use AdminService\exception\sql\ConnectionException;

/**
 * 数据库连接管理器
 */
class ConnectionManager {

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
     * 可复用连接池
     * @var ConnectionPool
     */
    protected ConnectionPool $normalConnections;

    /**
     * 不可复用的连接池
     * @var ConnectionPool
     */
    protected ConnectionPool $unreusableConnections;

    /**
     * 事务连接池
     * @var array<string,ConnectionPool>
     */
    protected array $transactionConnections;

    /**
     * 数据库连接配置
     * @var array<string,Config>
     */
    protected array $configs=[];

    /**
     * 默认连接名称
     * @var string
     */
    protected string $defaultConnectionName='';

    /**
     * 连接池标识
     * @var int
     */
    protected int $connectionId=0;

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
    ) {
        $this->configs=$configs;
        $this->defaultConnectionName=$default;
        $this->normalConnections=$normalConnections
            ??new ConnectionPool();
        $this->unreusableConnections=$unreusableConnections
            ??new ConnectionPool();
        $this->transactionConnections=$transactionConnections
            ??[];
    }

    /**
     * 注册自动销毁
     * 
     * @return void
     */
    public function registerAutoDestroy(): void {
        register_shutdown_function(
            [$this, 'destroyAllConnections']
        );
    }

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
     * @throws ConnectionException
     */
    public function get(?string $name=null): Connection {
        $name??=$this->defaultConnectionName;
        // 判断是否存在缓存的连接实例
        if($this->normalConnections->has($name)) {
            return $this->normalConnections->get($name);
        }
        // 新建连接实例
        $this->create($name,self::NORMAL_CONNECTION);
        return $this->normalConnections->get($name);
    }

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
     * @throws ConnectionException
     */
    public function create(
        ?string $name=null,
        int $type=self::UNREUSABLE_CONNECTION
    ): Connection {
        $name??=$this->defaultConnectionName;
        // 检查数据库配置是否存在
        if(!isset($this->configs[$name])) {
            throw new ConnectionException(
                '数据库连接配置不存在',
                0,
                [
                    'name'=>$name,
                ]
            );
        }
        // 连接池标识自增
        $this->connectionId++;
        // 实例化连接实例
        $connection=new Connection(
            $this->buildPdoLazy($this->configs[$name]),
            $this->connectionId
        );
        // 缓存连接实例
        switch($type) {
            case self::NORMAL_CONNECTION:
                // 设置可复用连接实例不允许开启事务
                $connection->setTransactionStates(Connection::TX_NOT_ALLOWED);
                $this->normalConnections->set($name,$connection);
                break;
            case self::TRANSACTION_CONNECTION:
                $this->setTransactionalConnection($name,$connection);
                break;
            default:
            $this->unreusableConnections->set(
                $this->connectionId,
                $connection
            );
                break;
        }
        return $connection;
    }

    /**
     * 创建一次性连接实例(建议优先考虑使用不可复用连接)
     * 
     *  - 创建一次性连接实例, 连接实例的生命周期为回调结束
     *  - 大量或反复创建会严重影响性能, 请优先考虑创建不可复用连接实例并手动管理
     * @template T
     * @param Closure(Connection): T $callback 回调函数
     *  - 回调函数仅接受一个参数, 即连接实例({@see \base\Sql\Connection})
     * @param string|null $name 数据库配置名称
     * @param Closure|null $onCloseError 连接关闭时发生的错误的回调
     *  - 回调函数仅接受一个参数, 即异常对象({@see \PDOException})
     *  - 如果为 `null`, 则不执行回调
     * @return T 回调函数返回值
     * @throws ConnectionException
     */
    public function createTemporaryConnection(
        Closure $callback,
        ?string $name=null,
        ?Closure $onCloseError=null
    ): mixed {
        $connection=$this->create($name,self::UNREUSABLE_CONNECTION);
        $connection->setOnCloseError(
            $onCloseError
        );
        try {
            $result=$callback($connection);
        } catch(Throwable $e) {
            throw $e;
        } finally {
            $connectionId=$connection->getConnectionId();
            $this->destroyUnreusableConnections($connectionId);
        }
        return $result;
    }

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
    ): void {
        // 判断是否存在对应的连接池
        if(!isset($this->transactionConnections[$name])) {
            $this->transactionConnections[$name]=new ConnectionPool();
        }
        // 存储连接实例
        $this->transactionConnections[$name]->set(
            $connection->getConnectionId(),
            $connection
        );
    }

    /**
     * 从事务连接池获取一个闲置的事务连接实例
     * 
     *  - 如果没有闲置的事务连接实例, 则新建一个事务连接实例
     *  - 如果有闲置的事务连接实例, 则从闲置的事务连接实例中获取一个
     * 
     * @param string|null $name 数据库配置名称
     * @return Connection 事务连接实例
     * @throws ConnectionException
     */
    public function getIdleTransactionalConnection(
        ?string $name=null,
    ): Connection {
        $name??=$this->defaultConnectionName;
        // 获取对应的连接池
        if(isset($this->transactionConnections[$name])) {
            $connectionPool=$this->transactionConnections[$name];
        } else {
            $connectionPool=new ConnectionPool();
        }
        // 尝试从连接池获取闲置的事务连接
        foreach($connectionPool as $connection) {
            if(
                !$connection->inTransaction()
                && $connection->getTransactionStates()===Connection::TX_IDLE
            ) {
                return $connection;
            }
        }
        // 创建新的事务连接实例
        return $this->create($name,self::TRANSACTION_CONNECTION);
    }

    /**
     * 构造 PDO 连接闭包
     * 
     * @param Config $config 数据库连接配置
     * @return Closure PDO 连接闭包
     */
    protected function buildPdoLazy(Config $config): Closure {
        return fn(): PDO => new PDO(
            $config->getDsn(),
            $config->getUsername(),
            $config->getPassword(),
            $config->getOptions()
        );
    }

    /**
     * 销毁不可复用的连接实例
     * 
     * @param int $connectionId 连接实例标识
     * @return void
     */
    public function destroyUnreusableConnections(int $connectionId): void {
        $this->unreusableConnections->destroy($connectionId);
    }

    /**
     * 销毁所有连接实例
     * 
     * @return void
     */
    public function destroyAllConnections(): void {
        $this->normalConnections->destroyAllConnections();
        $this->unreusableConnections->destroyAllConnections();
        foreach($this->transactionConnections as $pool) {
            $pool->destroyAllConnections();
        }
    }

}