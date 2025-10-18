<?php

namespace base\Database;

use \Countable;
use \ArrayAccess;
use \ArrayIterator;
use \IteratorAggregate;

/**
 * 数据库连接池
 */
class ConnectionPool implements Countable,IteratorAggregate,ArrayAccess {

    /**
     * 连接池
     * @var array<int|string,Connection>
     */
    protected array $connections=[];

    /**
     * 通过属性获取连接
     * @param int|string $name 连接名称
     * @return Connection|null
     */
    public function __get(int|string $name): ?Connection {
        return $this->get($name);
    }

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
     * 通过属性设置连接
     * @param int|string $name 连接名称
     * @param Connection $connection
     * @return void
     */
    public function __set(int|string $name,Connection $connection): void {
        $this->set($name,$connection);
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
     * 支持isset()访问
     * @param int|string $name 连接名称
     * @return bool
     */
    public function __isset(int|string $name): bool {
        return isset($this->connections[$name]);
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
     * 支持unset()访问
     * @param int|string $name
     * @return void
     */
    public function __unset(int|string $name): void {
        $this->destroy($name);
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
     * 偏移量是否存在
     * 
     * @param int|string $offset 偏移量
     * @return bool
     */
    public function offsetExists(mixed $offset): bool {
        return $this->has($offset);
    }

    /**
     * 获取偏移量对应的值
     * 
     * @param int|string $offset 偏移量
     * @return mixed
     */
    public function offsetGet(mixed $offset): mixed {
        return $this->get($offset);
    }

    /**
     * 设置偏移量对应的值
     * 
     * @param int|string $offset 偏移量
     * @param Connection $value 值
     * @return void
     */
    public function offsetSet(mixed $offset,mixed $value): void {
        $this->set($offset,$value);
    }

    /**
     * 删除偏移量对应的值
     * 
     * @param int|string $offset 偏移量
     * @return void
     */
    public function offsetUnset(mixed $offset): void {
        $this->destroy($offset);
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