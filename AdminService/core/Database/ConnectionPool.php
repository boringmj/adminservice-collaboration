<?php

namespace AdminService\Database;

use \ArrayIterator;
use base\Database\ConnectionInterface as Connection;
use base\Database\AbstractConnectionPool;

/**
 * 数据库连接池
 */
class ConnectionPool extends AbstractConnectionPool {

    /**
     * 连接池
     * @var array<int|string,Connection>
     */
    protected array $connections=[];

    /**
     * 获取连接
     * @param int|string $name 连接名称
     * @return Connection|null
     */
    public function get(int|string $name): ?Connection {
        if($this->has($name)) {
            return $this->connections[$name];
        }
        return null;
    }

    /**
     * 设置连接
     * @param int|string $name 连接名称
     * @param Connection $connection
     * @return void
     */
    public function set(int|string $name,Connection $connection): void {
        $this->connections[$name]=$connection;
    }

    /**
     * 判断是否存在连接
     * @param int|string $name 连接名称
     * @return bool
     */
    public function has(int|string $name): bool {
        return $this->__isset($name);
    }

    /**
     * 判断连接是否在事务中
     * @param int|string $name
     * @return bool
     */
    public function inTransaction(int|string $name): bool {
        if($this->has($name)) {
            return $this->connections[$name]->inTransaction();;
        }
        return false;
    }

    /**
     * 销毁连接
     * @param int|string $name
     * @return void
     */
    public function destroy(int|string $name): void {
        if($this->has($name)) {
            $this->connections[$name]->close();
            unset($this->connections[$name]);
        }
    }

    /**
     * 获取连接数量
     * @return int
     */
    public function count(): int {
        return count($this->connections);
    }

    /**
     * 获取迭代器
     * @return ArrayIterator<int|string,Connection>
     */
    public function getIterator(): ArrayIterator {
        return new ArrayIterator($this->connections);
    }

    /**
     * 销毁连接池
     * 
     * @return void
     */
    public function destroyAllConnections(): void {
        foreach($this->connections as $connection) {
            $connection->close();
        }
        $this->connections=[];
    }

}