<?php

namespace base\Database\Coordinator;

use base\Database\Connection\ConnectionManagerInterface;
use base\Database\Connection\ConnectionSessionInterface;
use base\Database\Coordinator\Handler\QueryBuildHandler;
use base\Database\Coordinator\Handler\QueryBuildHandlerInterface;
use base\Database\Coordinator\Handler\QueryMiddlewareHandler;
use base\Database\Middleware\QueryMiddlewareInterface;
use base\Database\Query\QueryContextInterface;
use base\Database\Result\ResultInterface;
use base\Database\Sql\Builder\StatementBuilderInterface;
use base\Database\Sql\Compiler\CompiledStatementInterface;
use base\Database\Sql\Compiler\SqlCompilerInterface;

/**
 * 查询协调器
 *
 * - 串联连接分配、语句构建、编译、中间件链与执行
 * - 读写统一走连接池(共享连接), 查询失败时标记会话污染, 归还时由池丢弃
 */
final class QueryCoordinator implements QueryCoordinatorInterface {

    /**
     * 查询上下文
     * @var QueryContextInterface
     */
    private QueryContextInterface $context;

    /**
     * 连接管理器
     * @var ConnectionManagerInterface
     */
    private ConnectionManagerInterface $connectionManager;

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
     * 查询构建处理器
     * @var QueryBuildHandlerInterface
     */
    private QueryBuildHandlerInterface $buildHandler;

    /**
     * 构造方法
     *
     * @access public
     * @param QueryContextInterface $context 查询上下文
     * @param ConnectionManagerInterface $connectionManager 连接管理器
     * @param SqlCompilerInterface $compiler SQL 编译器
     * @param array<QueryMiddlewareInterface> $middlewares 中间件数组
     * @param QueryBuildHandlerInterface|null $buildHandler 查询构建处理器
     */
    public function __construct(
        QueryContextInterface $context,
        ConnectionManagerInterface $connectionManager,
        SqlCompilerInterface $compiler,
        array $middlewares=array(),
        ?QueryBuildHandlerInterface $buildHandler=null
    ) {
        $this->context=$context;
        $this->connectionManager=$connectionManager;
        $this->compiler=$compiler;
        $this->middlewares=$middlewares;
        $this->buildHandler=$buildHandler??new QueryBuildHandler();
    }

    /**
     * 协调查询
     *
     * - $session 为 null 时从连接池借连接执行, 结束后归还; 失败标记脏由池丢弃
     * - $session 已指定时(事务会话)直接用该会话, 不借不还、不标记脏, 事务生命周期由上层管理
     *
     * @access public
     * @param StatementBuilderInterface $builder SQL语句构建器
     * @param ConnectionSessionInterface|null $session 指定会话(事务)时为 null 则走连接池
     * @return ResultInterface
     */
    public function query(StatementBuilderInterface $builder,?ConnectionSessionInterface $session=null): ResultInterface {
        $connection=$session??$this->connectionManager->getConnection();
        try {
            $statement=$this->buildHandler->execute(
                $this->context,
                $connection,
                $builder,
                $this->compiler
            );
            $result=$this->run($connection,$statement);
            // 池连接查询失败 → 状态未知, 标记脏, 归还时丢弃不复用
            if($session===null&&!$result->isSuccess())
                $connection->markDirty();
            return $result;
        } finally {
            if($session===null)
                $connection->release();
        }
    }

    /**
     * 执行已编译的原生语句
     *
     * - 与 query() 共享同一中间件链/执行流水线, 仅跳过语句构建与编译环节
     * - $markDirtyOnSessionModify: 原生 SQL 修改了会话状态(SET/USE/LOCK/临时表等)时,
     *   池连接归还须丢弃避免污染其他使用者
     *
     * @access public
     * @param CompiledStatementInterface $statement 已编译语句
     * @param ConnectionSessionInterface|null $session 指定会话(事务)时为 null 则走连接池
     * @param bool $markDirtyOnSessionModify 原生 SQL 是否修改会话状态
     * @return ResultInterface
     */
    public function raw(
        CompiledStatementInterface $statement,
        ?ConnectionSessionInterface $session=null,
        bool $markDirtyOnSessionModify=false
    ): ResultInterface {
        $connection=$session??$this->connectionManager->getConnection();
        try {
            $result=$this->run($connection,$statement);
            if($session===null&&($markDirtyOnSessionModify||!$result->isSuccess()))
                $connection->markDirty();
            return $result;
        } finally {
            if($session===null)
                $connection->release();
        }
    }

    /**
     * 在指定会话上执行已编译语句(中间件链 + 执行)
     *
     * @access private
     * @param ConnectionSessionInterface $connection 连接会话
     * @param CompiledStatementInterface $statement 已编译语句
     * @return ResultInterface
     */
    private function run(ConnectionSessionInterface $connection,CompiledStatementInterface $statement): ResultInterface {
        $dispatcher=new QueryExecutionDispatcher();
        $handler=(new QueryMiddlewareHandler($dispatcher,$this->middlewares))
            ->configure($connection->getSqlExecutor(),$statement);
        return $handler->execute($this->context);
    }

}
