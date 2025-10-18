<?php

namespace Tests;

use PHPUnit\Framework\TestCase;

use AdminService\App;
use AdminService\Config;
use AdminService\Database\Config as DbConfig;
use AdminService\Database\Connection;
use base\Database\ConnectionInterface;
use AdminService\Database\ConnectionPool;
use base\Database\AbstractConnectionPool;
use AdminService\Database\ConnectionManager;

class ConnectionManagerTest extends TestCase {

    /**
     * 类初始化前执行
     * @return void
     */
    public static function setUpBeforeClass(): void {
        Config::load();
        App::bind(ConnectionInterface::class, Connection::class);
        App::bind(AbstractConnectionPool::class, ConnectionPool::class);
    }

    /**
     * 测试ConnectionManager::get()方法是否返回Connection实例
     * @return void
     */
    public function testGet_returnsConnectionInstance(): void {
        $dbConfig = new DbConfig();
        $connectionManager = new ConnectionManager(
            ['default'=>$dbConfig]
        );
        $connection=$connectionManager->get();
        $this->assertInstanceOf(Connection::class, $connection);
    }

}