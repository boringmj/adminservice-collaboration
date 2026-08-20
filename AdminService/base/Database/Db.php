<?php

namespace base\Database;

use Throwable;
use AdminService\Config;
use base\Database\Connection\ConnectionConfig;
use base\Database\Connection\ConnectionManagerInterface;
use base\Database\Connection\ConnectionSessionInterface;
use base\Database\Connection\PdoConnectionManager;
use base\Database\Connection\PdoConnectionPool;
use base\Database\Coordinator\Handler\QueryMiddlewareHandler;
use base\Database\Coordinator\QueryCoordinator;
use base\Database\Coordinator\QueryExecutionDispatcher;
use base\Database\Query\QueryContext;
use base\Database\Query\QueryInterface;
use base\Database\Result\ResultInterface;
use base\Database\Sql\Builder\QueryStatementBuilder;
use base\Database\Sql\Compiler\MysqlCompiler;
use base\Database\Sql\Compiler\SqlCompilerInterface;
use base\Database\Transaction\TransactionContextInterface;

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
     * @access public
     * @param array $config 连接配置(覆盖框架配置)
     * @return static
     */
    public static function fromConfig(array $config=array()): static {
        $db_config=new ConnectionConfig(
            $config['type']??Config::get('database.default.type','mysql'),
            $config['host']??Config::get('database.default.host','localhost'),
            (int)($config['port']??Config::get('database.default.port',3306)),
            $config['user']??Config::get('database.default.user',''),
            $config['password']??Config::get('database.default.password',''),
            $config['dbname']??Config::get('database.default.dbname',''),
            $config['charset']??Config::get('database.default.charset','utf8mb4'),
            $config['options']??Config::get('database.default.options',array()),
            $config['prefix']??Config::get('database.default.prefix','')
        );
        $factory=$db_config->sessionFactory();
        $manager=new PdoConnectionManager(new PdoConnectionPool($factory),$factory);
        return new static($manager,new MysqlCompiler(),Config::get('database.middlewares',array()));
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
            $tx->begin();
            try {
                $result=$callback($this);
                $tx->commit();
                return $result;
            } catch(Throwable $e) {
                try {
                    $tx->rollback();
                } catch(Throwable $ignored) {
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
