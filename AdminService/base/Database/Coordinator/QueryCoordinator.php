<?php

namespace base\Database\Coordinator;

use base\Database\Connection\ConnectionManagerInterface;
use base\Database\Coordinator\Handler\QueryBuildHandler;
use base\Database\Coordinator\Handler\QueryBuildHandlerInterface;
use base\Database\Coordinator\Handler\QueryMiddlewareHandler;
use base\Database\Query\QueryContextInterface;
use base\Database\Result\ResultInterface;
use base\Database\Sql\Builder\StatementBuilderInterface;
use base\Database\Sql\Compiler\SqlCompilerInterface;
use base\Database\Type\QueryType;

/**
 * 查询协调器
 *
 * - 串联读/写连接分配、语句构建、编译、中间件链与执行
 * - 写查询使用独占连接, 读查询使用共享连接
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
     * @param array $middlewares 中间件数组
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
     * @access public
     * @param StatementBuilderInterface $builder SQL语句构建器
     * @return ResultInterface
     */
    public function query(StatementBuilderInterface $builder): ResultInterface {
        $write=($this->context->getQueryType()&QueryType::WRITE)!==0;
        $connection=$write
            ? $this->connectionManager->getExclusiveConnection()
            : $this->connectionManager->getConnection();
        try {
            $statement=$this->buildHandler->execute(
                $this->context,
                $connection,
                $builder,
                $this->compiler
            );
            $executor=$connection->getSqlExecutor();
            $dispatcher=new QueryExecutionDispatcher();
            $handler=(new QueryMiddlewareHandler($dispatcher,$this->middlewares))
                ->configure($executor,$statement);
            return $handler->execute($this->context);
        } finally {
            $connection->release();
        }
    }

}
