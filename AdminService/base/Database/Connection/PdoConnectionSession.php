<?php

namespace base\Database\Connection;

use PDO;
use base\Database\Exception\TransactionException;
use base\Database\Execution\PdoSqlExecutor;
use base\Database\Execution\SqlExecutorInterface;
use base\Database\Execution\TransactionExecutorInterface;
use base\Database\Sql\Compiler\CompileModeSet;
use base\Database\Sql\Compiler\CompilerContext;
use base\Database\Sql\Compiler\CompilerContextInterface;
use base\Database\Sql\Compiler\DefaultNamingStrategy;
use base\Database\Sql\Compiler\NamingStrategyInterface;
use base\Database\Sql\Dialect\DialectInterface;
use base\Database\Sql\Dialect\MysqlDialect;
use base\Database\Transaction\NestedTransactionExecutor;
use base\Database\Transaction\TransactionContext;
use base\Database\Transaction\TransactionContextInterface;
use base\Database\Transaction\TransactionState;

/**
 * PDO 连接会话
 *
 * - 管理连接资源、事务与执行器
 * - 持有方言、表前缀与命名策略, 可提供编译器上下文
 * - 支持归属连接池: 释放时回到连接池(先清理事务状态)
 */
final class PdoConnectionSession implements ConnectionSessionInterface {

    /**
     * 方言
     * @var DialectInterface
     */
    private DialectInterface $dialect;

    /**
     * 表前缀
     * @var string
     */
    private string $tablePrefix;

    /**
     * 命名策略
     * @var NamingStrategyInterface
     */
    private NamingStrategyInterface $namingStrategy;

    /**
     * SQL 执行器
     * @var SqlExecutorInterface
     */
    private SqlExecutorInterface $sqlExecutor;

    /**
     * 事务上下文
     * @var TransactionContextInterface
     */
    private TransactionContextInterface $transactionContext;

    /**
     * 事务执行器
     * @var TransactionExecutorInterface
     */
    private TransactionExecutorInterface $transactionExecutor;

    /**
     * 编译器上下文
     * @var CompilerContextInterface
     */
    private CompilerContextInterface $compilerContext;

    /**
     * 所属连接池
     * @var ConnectionPoolInterface|null
     */
    private ?ConnectionPoolInterface $pool=null;

    /**
     * 是否已释放
     * @var bool
     */
    private bool $released=false;

    /**
     * 是否已污染(查询失败或执行了修改会话状态的 SQL)
     *
     * - 标记后归还连接池时被丢弃, 不复用
     * - 借出(checkout)时清除
     *
     * @var bool
     */
    private bool $dirty=false;

    /**
     * 构造方法
     *
     * @access public
     * @param PDO $connection 裸连接对象
     * @param DialectInterface|null $dialect 方言
     * @param string $tablePrefix 表前缀
     * @param NamingStrategyInterface|null $namingStrategy 命名策略
     */
    public function __construct(
        PDO $connection,
        ?DialectInterface $dialect=null,
        string $tablePrefix='',
        ?NamingStrategyInterface $namingStrategy=null
    ) {
        $this->dialect=$dialect??new MysqlDialect();
        $this->tablePrefix=$tablePrefix;
        $this->namingStrategy=$namingStrategy??new DefaultNamingStrategy();
        $this->sqlExecutor=new PdoSqlExecutor($connection);
        $state=new TransactionState();
        $this->transactionExecutor=new NestedTransactionExecutor($connection,$state);
        $this->transactionContext=new TransactionContext($state);
        $this->compilerContext=new CompilerContext(
            $this->dialect,
            CompileModeSet::none(),
            $this->tablePrefix,
            $this->namingStrategy
        );
    }

    /**
     * 获取 SQL 执行器
     *
     * @access public
     * @return SqlExecutorInterface
     */
    public function getSqlExecutor(): SqlExecutorInterface {
        return $this->sqlExecutor;
    }

    /**
     * 获取事务上下文
     *
     * @access public
     * @return TransactionContextInterface
     */
    public function getTransactionContext(): TransactionContextInterface {
        return $this->transactionContext;
    }

    /**
     * 获取事务执行器
     *
     * @access public
     * @return TransactionExecutorInterface
     */
    public function getTransactionExecutor(): TransactionExecutorInterface {
        return $this->transactionExecutor;
    }

    /**
     * 获取支持的方言
     *
     * @access public
     * @return DialectInterface
     */
    public function getDialect(): DialectInterface {
        return $this->dialect;
    }

    /**
     * 获取编译器上下文
     *
     * @access public
     * @return CompilerContextInterface
     */
    public function getCompilerContext(): CompilerContextInterface {
        return $this->compilerContext;
    }

    /**
     * 设置所属连接池(仅连接池调用)
     *
     * @access public
     * @param PdoConnectionPool|null $pool 连接池
     * @return void
     */
    public function setPool(?ConnectionPoolInterface $pool): void {
        $this->pool=$pool;
    }

    /**
     * 标记会话已取出使用(仅连接池调用)
     *
     * @access public
     * @return void
     */
    public function checkout(): void {
        $this->released=false;
        $this->dirty=false;
    }

    /**
     * 释放连接资源
     *
     * - 归属连接池时释放后回到连接池(由池决定复用或丢弃)
     * - 独占会话(非池化)同样执行 reset(), 回滚残留事务后释放
     *
     * @access public
     * @return void
     */
    public function release(): void {
        if($this->released)
            return;
        if($this->pool!==null)
            // 归还走池契约(归属校验 + 幂等 + 标记释放均在池侧完成)
            $this->pool->release($this);
        else {
            // 独占会话: 标记释放后直接清理
            $this->released=true;
            $this->reset();
        }
    }

    /**
     * 标记会话已释放(由池归还时调用, 与 release() 守卫配合防重复归还)
     *
     * @access public
     * @return void
     */
    public function markReleased(): void {
        $this->released=true;
    }

    /**
     * 获取所属连接池(未归属则为 null)
     *
     * @access public
     * @return PdoConnectionPool|null
     */
    public function getPool(): ?ConnectionPoolInterface {
        return $this->pool;
    }

    /**
     * 标记会话已污染
     *
     * - 查询失败或执行了修改会话状态的 SQL 时调用
     * - 归还连接池时将被丢弃, 不复用
     *
     * @access public
     * @return void
     */
    public function markDirty(): void {
        $this->dirty=true;
    }

    /**
     * 判断会话是否已污染
     *
     * @access public
     * @return bool
     */
    public function isDirty(): bool {
        return $this->dirty;
    }

    /**
     * 获取是否已释放
     *
     * @access public
     * @return bool
     */
    public function isReleased(): bool {
        return $this->released;
    }

    /**
     * 重置连接会话
     *
     * - 回滚未完成的事务, 清理会话状态
     *
     * @access public
     * @return void
     */
    public function reset(): void {
        if($this->transactionContext->isActive()) {
            try {
                $this->transactionExecutor->rollback();
            } catch(TransactionException $ignored) {
                // 清理过程中的回滚失败不阻断
            }
        }
    }

}
