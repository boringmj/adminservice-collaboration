<?php

namespace base\Database;

use Throwable;
use AdminService\App;
use AdminService\Config;
use base\Database\Connection\ConnectionConfig;
use base\Database\Connection\ConnectionManagerInterface;
use base\Database\Connection\ConnectionSessionInterface;
use base\Database\Connection\PdoConnectionManager;
use base\Database\Connection\PdoConnectionPool;
use base\Database\Coordinator\Handler\QueryMiddlewareHandler;
use base\Database\Coordinator\QueryCoordinator;
use base\Database\Coordinator\QueryExecutionDispatcher;
use base\Database\Exception\ConfigException;
use base\Database\Middleware\QueryMiddlewareInterface;
use base\Database\Query\Query;
use base\Database\Query\QueryContext;
use base\Database\Query\QueryInterface;
use base\Database\Result\ResultInterface;
use base\Database\Sql\Builder\QueryStatementBuilder;
use base\Database\Sql\Compiler\CompiledStatement;
use base\Database\Sql\Compiler\MysqlCompiler;
use base\Database\Sql\Compiler\SqlCompilerInterface;
use base\Database\Transaction\TransactionContextInterface;

use function array_merge;
use function get_class;
use function implode;
use function is_array;
use function is_object;
use function serialize;
use function spl_object_hash;

/**
 * 数据库入口(门面)
 *
 * - 新 DBAL 的公共执行入口, 与 Query 流式构建器配合使用
 * - 执行查询返回 Result, 支持事务作用域(可嵌套)与手动事务控制
 */
final class Db {

    /**
     * 连接管理器
     * @var ConnectionManagerInterface
     */
    private ConnectionManagerInterface $manager;

    /**
     * SQL 编译器
     * @var SqlCompilerInterface
     */
    private SqlCompilerInterface $compiler;

    /**
     * 中间件数组
     * @var array
     */
    private array $middlewares;

    /**
     * 绑定的事务会话(事务作用域/手动事务期间持有)
     * @var ConnectionSessionInterface|null
     */
    private ?ConnectionSessionInterface $session=null;

    /**
     * 按「连接名 + 配置指纹」缓存的数据库入口(纯配置调用共享单例)
     * @var array
     */
    private static array $instances=array();

    /**
     * 构造方法
     *
     * @access public
     * @param ConnectionManagerInterface $manager 连接管理器
     * @param SqlCompilerInterface|null $compiler SQL 编译器
     * @param array $middlewares 中间件数组
     */
    public function __construct(
        ConnectionManagerInterface $manager,
        ?SqlCompilerInterface $compiler=null,
        array $middlewares=array()
    ) {
        $this->manager=$manager;
        $this->compiler=$compiler??new MysqlCompiler();
        $this->middlewares=$middlewares;
    }

    /**
     * 从框架配置创建数据库入口
     *
     * - 按名称选择配置文件中 database.connections.{name} 对应的连接
     * - $config 可覆盖所选连接的任意配置项(也可全量手动配置)
     *
     * @access public
     * @param string $name 连接名称(默认 default)
     * @param array $config 连接配置覆盖项
     * @return static
     * @throws ConfigException 指定连接未配置且未提供覆盖
     */
    public static function fromConfig(string $name='default',array $config=array()): static {
        $base=Config::get('database.connections.'.$name,array());
        if(!is_array($base))
            $base=array();
        if(empty($base)&&empty($config))
            throw new ConfigException('Database connection "'.$name.'" is not configured.',100801,array(
                'name'=>$name
            ));
        $merged=array_merge($base,$config);
        $middleware_config=Config::get('database.middlewares',array());
        // 纯配置调用(无手动覆盖)按「连接名 + 连接配置 + 中间件配置」缓存单例:
        // - 同连接共享同一 Db → 同一连接池/编译器/方言, 连接真正复用
        // - 配置变化(含中间件实例)指纹随之变化, 自动失效重建
        // 手动覆盖($config 非空)视为一次性调用, 不缓存
        $cache_key=null;
        if(empty($config)) {
            $cache_key=$name.'|'.self::configFingerprint($merged).'|'.self::configFingerprint($middleware_config);
            if(isset(self::$instances[$cache_key]))
                return self::$instances[$cache_key];
        }
        $db_config=self::buildConfig($merged);
        $factory=$db_config->sessionFactory();
        $manager=new PdoConnectionManager(new PdoConnectionPool($factory),$factory);
        // 编译器可通过配置手动绑定, 未指定则使用 MySQL
        $compilerClass=$merged['compiler']??MysqlCompiler::class;
        $compiler=new $compilerClass();
        if(!$compiler instanceof SqlCompilerInterface)
            throw new ConfigException('Invalid compiler class.',100803,array(
                'class'=>$compilerClass
            ));
        $middlewares=self::resolveMiddlewares($middleware_config);
        $instance=new static($manager,$compiler,$middlewares);
        if($cache_key!==null)
            self::$instances[$cache_key]=$instance;
        return $instance;
    }

    /**
     * 计算配置指纹(用于缓存键)
     *
     * - 标量/数组按值序列化, 对象按「类名 + 实例哈希」标记
     *   (实例变化即视为不同配置, 防止中间件实例差异被缓存掩盖)
     *
     * @access private
     * @param mixed $value 配置值
     * @return string 指纹
     */
    private static function configFingerprint(mixed $value): string {
        if(is_object($value))
            return 'obj:'.get_class($value).':'.spl_object_hash($value);
        if(!is_array($value))
            return serialize($value);
        $parts=array();
        foreach($value as $k=>$v)
            $parts[]=serialize($k).':'.self::configFingerprint($v);
        return '['.implode(',',$parts).']';
    }

    /**
     * 解析中间件配置
     *
     * - 支持类名(通过框架容器 App::get 解析, 可依赖注入)与已实例化对象
     * - 解析结果必须是 QueryMiddlewareInterface 实例
     *
     * @access private
     * @param array $middlewares 中间件配置(类名或实例列表)
     * @return QueryMiddlewareInterface[]
     * @throws ConfigException 中间件未实现 QueryMiddlewareInterface
     */
    private static function resolveMiddlewares(array $middlewares): array {
        $resolved=array();
        foreach($middlewares as $middleware) {
            if(is_string($middleware))
                $middleware=App::get($middleware);
            if(!$middleware instanceof QueryMiddlewareInterface)
                throw new ConfigException('Invalid middleware.',100804,array(
                    'middleware'=>get_debug_type($middleware)
                ));
            $resolved[]=$middleware;
        }
        return $resolved;
    }

    /**
     * 从配置数组构建连接配置
     *
     * @access private
     * @param array $config 连接配置
     * @return ConnectionConfig
     */
    private static function buildConfig(array $config): ConnectionConfig {
        return new ConnectionConfig(
            $config['type']??'mysql',
            $config['host']??'localhost',
            (int)($config['port']??3306),
            $config['user']??'',
            $config['password']??'',
            $config['dbname']??'',
            $config['charset']??'utf8mb4',
            $config['options']??array(),
            $config['prefix']??'',
            $config['dialect']??null
        );
    }

    /**
     * 执行查询
     *
     * @access public
     * @param QueryInterface $query 查询对象
     * @return ResultInterface
     */
    public function query(QueryInterface $query): ResultInterface {
        $context=new QueryContext($query);
        if($this->session!==null) {
            // 事务会话上直接执行
            $definition=(new QueryStatementBuilder())->build($context->getQuery());
            $statement=$this->compiler->compile($definition,$this->session->getCompilerContext());
            $dispatcher=new QueryExecutionDispatcher();
            $handler=(new QueryMiddlewareHandler($dispatcher,$this->middlewares))
                ->configure($this->session->getSqlExecutor(),$statement);
            return $handler->execute($context);
        }
        $coordinator=new QueryCoordinator($context,$this->manager,$this->compiler,$this->middlewares);
        return $coordinator->query(new QueryStatementBuilder());
    }

    /**
     * 执行原生 SQL
     *
     * - 适用于构建器无法表达的语句(DDL、SHOW、SET 等)
     * - 同样经过中间件链, 事务中复用当前会话
     *
     * @access public
     * @param string $sql SQL
     * @param array $params 绑定参数
     * @return ResultInterface
     */
    public function raw(string $sql,array $params=array()): ResultInterface {
        $statement=new CompiledStatement($sql,$params);
        $context=new QueryContext(Query::select());
        if($this->session!==null) {
            $dispatcher=new QueryExecutionDispatcher();
            $handler=(new QueryMiddlewareHandler($dispatcher,$this->middlewares))
                ->configure($this->session->getSqlExecutor(),$statement);
            return $handler->execute($context);
        }
        $connection=$this->manager->getConnection();
        try {
            $dispatcher=new QueryExecutionDispatcher();
            $handler=(new QueryMiddlewareHandler($dispatcher,$this->middlewares))
                ->configure($connection->getSqlExecutor(),$statement);
            return $handler->execute($context);
        } finally {
            $connection->release();
        }
    }

    /**
     * 事务作用域
     *
     * - 回调内所有查询在同一个独占会话上执行
     * - 支持嵌套, 嵌套事务通过保存点模拟
     * - 回调抛出任何异常都会回滚并重新抛出
     *
     * @access public
     * @param callable $callback 回调(callable(Db $db): mixed)
     * @return mixed 回调返回值
     * @throws Throwable
     */
    public function transaction(callable $callback): mixed {
        if($this->session!==null) {
            // 嵌套: 复用当前会话, 通过保存点实现
            $tx=$this->session->getTransactionExecutor();
            $context=$this->session->getTransactionContext();
            $tx->begin();
            try {
                $result=$callback($this);
                // 回调内可能已手动提交/回滚, 仅在仍活跃时收尾
                if($context->isActive())
                    $tx->commit();
                return $result;
            } catch(Throwable $e) {
                if($context->isActive()) {
                    try {
                        $tx->rollback();
                    } catch(Throwable $ignored) {
                    }
                }
                throw $e;
            }
        }
        $session=$this->manager->getExclusiveConnection();
        try {
            return $this->withSession($session)->transaction($callback);
        } finally {
            $session->release();
        }
    }

    /**
     * 手动开启事务(本实例后续查询在事务会话上执行)
     *
     * @access public
     * @return static
     */
    public function beginTransaction(): static {
        if($this->session===null)
            $this->session=$this->manager->getExclusiveConnection();
        $this->session->getTransactionExecutor()->begin();
        return $this;
    }

    /**
     * 提交手动开启的事务
     *
     * @access public
     * @return void
     */
    public function commit(): void {
        if($this->session!==null) {
            $this->session->getTransactionExecutor()->commit();
            $this->session->release();
            $this->session=null;
        }
    }

    /**
     * 回滚手动开启的事务
     *
     * @access public
     * @return void
     */
    public function rollBack(): void {
        if($this->session!==null) {
            $this->session->getTransactionExecutor()->rollback();
            $this->session->release();
            $this->session=null;
        }
    }

    /**
     * 获取当前事务上下文
     *
     * @access public
     * @return TransactionContextInterface|null
     */
    public function getTransactionContext(): ?TransactionContextInterface {
        return $this->session?->getTransactionContext();
    }

    /**
     * 绑定事务会话(返回新实例, 不影响当前实例)
     *
     * @access private
     * @param ConnectionSessionInterface $session 连接会话
     * @return static
     */
    private function withSession(ConnectionSessionInterface $session): static {
        $clone=clone $this;
        $clone->session=$session;
        return $clone;
    }

}
