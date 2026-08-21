<?php

namespace base\Database\Coordinator\Handler;

use base\Database\Coordinator\QueryExecutionDispatcherInterface;
use base\Database\Exception\ExecutionException;
use base\Database\Execution\SqlExecutorInterface;
use base\Database\Middleware\QueryMiddlewareInterface;
use base\Database\Query\QueryContextInterface;
use base\Database\Result\ResultInterface;
use base\Database\Sql\Compiler\CompiledStatementInterface;

use function count;

/**
 * 查询中间件处理器
 *
 * - 将中间件链包裹执行调度器并执行
 * - wrap 遵循不可变模式, 不修改当前实例状态
 */
final class QueryMiddlewareHandler implements QueryMiddlewareHandlerInterface {

    /**
     * 查询执行调度器
     * @var QueryExecutionDispatcherInterface
     */
    private QueryExecutionDispatcherInterface $dispatcher;

    /**
     * 中间件数组
     * @var QueryMiddlewareInterface[]
     */
    private array $middlewares;

    /**
     * SQL 执行器(执行前注入)
     * @var SqlExecutorInterface|null
     */
    private ?SqlExecutorInterface $executor=null;

    /**
     * 已编译语句(执行前注入)
     * @var CompiledStatementInterface|null
     */
    private ?CompiledStatementInterface $statement=null;

    /**
     * 构造方法
     *
     * @access public
     * @param QueryExecutionDispatcherInterface $dispatcher 查询执行调度器
     * @param array<QueryMiddlewareInterface> $middlewares 中间件数组
     */
    public function __construct(QueryExecutionDispatcherInterface $dispatcher,array $middlewares=array()) {
        $this->dispatcher=$dispatcher;
        $this->middlewares=$middlewares;
    }

    /**
     * 封装查询执行器到中间件链中
     *
     * - 遵循不可变模式, 返回新实例
     *
     * @access public
     * @param QueryExecutionDispatcherInterface $dispatcher 查询执行分发器对象
     * @param array<QueryMiddlewareInterface> $middlewares 中间件数组
     * @return static
     */
    public function wrap(QueryExecutionDispatcherInterface $dispatcher,array $middlewares): static {
        return new static($dispatcher,$middlewares);
    }

    /**
     * 注入执行器与已编译语句(执行前由协调器调用)
     *
     * @access public
     * @param SqlExecutorInterface $executor SQL 执行器
     * @param CompiledStatementInterface $statement 已编译语句
     * @return static
     */
    public function configure(SqlExecutorInterface $executor,CompiledStatementInterface $statement): static {
        $this->executor=$executor;
        $this->statement=$statement;
        return $this;
    }

    /**
     * 执行查询中间件链
     *
     * @access public
     * @param QueryContextInterface $context 查询上下文对象
     * @return ResultInterface
     * @throws ExecutionException
     */
    public function execute(QueryContextInterface $context): ResultInterface {
        if($this->executor===null||$this->statement===null)
            throw new ExecutionException('Query middleware handler is not configured.',100701);
        $next=new TerminalQueryHandler($this->dispatcher,$this->executor,$this->statement);
        // 倒序包裹, 使第一个中间件最先执行
        for($i=count($this->middlewares)-1;$i>=0;$i--)
            $next=new QueryMiddlewareLink($this->middlewares[$i],$next);
        return $next->handle($context);
    }

}
