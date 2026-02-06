<?php

namespace base\Database;

abstract class AbstractQueryExecutor implements QueryExecutorInterface
{
    /**
     * 查询驱动
     * @var QueryDriverInterface
     */
    protected QueryDriverInterface $driver;

    /**
     * 连接实例
     * @var AbstractConnection
     */
    protected AbstractConnection $connection;

    /**
     * 结果集处理器
     * @var ResultProcessorInterface
     */
    protected ResultProcessorInterface $processor;

    /**
     * 构造函数
     * 
     * @param QueryDriverInterface $driver 查询驱动
     * @param AbstractConnection $connection 连接实例
     * @param ResultProcessorInterface $processor 结果集处理器
     */
    public function __construct(
        QueryDriverInterface $driver,
        AbstractConnection $connection,
        ResultProcessorInterface $processor
    ) {
        $this->driver=$driver;
        $this->connection=$connection;
        $this->processor=$processor;
    }

}