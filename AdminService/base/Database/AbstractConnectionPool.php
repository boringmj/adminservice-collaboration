<?php

namespace base\Database;

use \Countable;
use \ArrayAccess;
use \ArrayIterator;
use \IteratorAggregate;
use base\Database\ConnectionInterface as Connection;

/**
 * 数据库连接池抽象类
 */
abstract class AbstractConnectionPool implements Countable,IteratorAggregate,ArrayAccess {

    /**
     * 获取连接
     * @param int|string $name 连接名称
     * @return Connection|null
     */
    abstract public function get(int|string $name): ?Connection;

    /**
     * 设置连接
     * @param int|string $name 连接名称
     * @param Connection $connection
     * @return void
     */
    abstract public function set(int|string $name,Connection $connection): void;
    
    /**
     * 判断是否存在连接
     * @param int|string $name 连接名称
     * @return bool
     */
    abstract public function has(int|string $name): bool;

    /**
     * 判断连接是否在事务中
     * @param int|string $name
     * @return bool
     */
    abstract public function inTransaction(int|string $name): bool;

    /**
     * 销毁连接
     * @param int|string $name
     * @return void
     */
    abstract public function destroy(int|string $name): void;

    /**
     * 获取连接数量
     * @return int
     */
    abstract public function count(): int;

    /**
     * 获取迭代器
     * @return ArrayIterator<int|string,Connection>
     */
    abstract public function getIterator(): ArrayIterator;

    /**
     * 销毁连接池
     * @return void
     */
    abstract public function destroyAllConnections(): void;

    /**
     * 通过属性获取连接
     * @param int|string $name 连接名称
     * @return Connection|null
     */
    public function __get(int|string $name): ?Connection {
        return $this->get($name);
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
     * 支持isset()访问
     * @param int|string $name 连接名称
     * @return bool
     */
    public function __isset(int|string $name): bool {
        return $this->has($name);
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
     * 偏移量是否存在
     * @param int|string $offset 偏移量
     * @return bool
     */
    public function offsetExists(mixed $offset): bool {
        return $this->has($offset);
    }

    /**
     * 获取偏移量对应的值
     * @param int|string $offset 偏移量
     * @return mixed
     */
    public function offsetGet(mixed $offset): mixed {
        return $this->get($offset);
    }

    /**
     * 设置偏移量对应的值
     * @param int|string $offset 偏移量
     * @param Connection $value 值
     * @return void
     */
    public function offsetSet(mixed $offset,mixed $value): void {
        $this->set($offset,$value);
    }

    /**
     * 删除偏移量对应的值
     * @param int|string $offset 偏移量
     * @return void
     */
    public function offsetUnset(mixed $offset): void {
        $this->destroy($offset);
    }

}