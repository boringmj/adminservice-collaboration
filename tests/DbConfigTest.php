<?php

namespace Tests;

use PHPUnit\Framework\TestCase;

use AdminService\Config;
use base\Database\Connection\ConnectionConfig;
use base\Database\Db;
use base\Database\Exception\ConfigException;
use base\Database\Sql\Compiler\MysqlCompiler;
use base\Database\Sql\Dialect\MysqlDialect;
use Tests\Fixtures\FakeDialect;

/**
 * 数据库入口配置测试
 *
 * - 验证 Db::fromConfig 按名称选择命名连接、手动覆盖与缺失连接报错
 */
class DbConfigTest extends TestCase {

    /**
     * 每个测试前加载真实配置
     * @return void
     */
    protected function setUp(): void {
        Config::load();
    }

    /**
     * 每个测试后恢复真实配置
     * @return void
     */
    protected function tearDown(): void {
        Config::load();
    }

    /**
     * 测试默认连接
     * @return void
     */
    public function testDefaultConnection(): void {
        $db=Db::fromConfig();
        $this->assertInstanceOf(Db::class,$db);
    }

    /**
     * 测试按名称选择命名连接
     * @return void
     */
    public function testNamedConnection(): void {
        Config::set(array(
            'database'=>array(
                'connections'=>array(
                    'default'=>array('type'=>'mysql','dbname'=>'main'),
                    'log'=>array('type'=>'mysql','dbname'=>'log_db'),
                ),
            ),
        ));
        $db=Db::fromConfig('log');
        $this->assertInstanceOf(Db::class,$db);
    }

    /**
     * 测试连接配置覆盖
     * @return void
     */
    public function testConfigOverride(): void {
        Config::set(array(
            'database'=>array(
                'connections'=>array(
                    'default'=>array('type'=>'mysql','dbname'=>'main'),
                ),
            ),
        ));
        $db=Db::fromConfig('default',array('dbname'=>'override'));
        $this->assertInstanceOf(Db::class,$db);
    }

    /**
     * 测试未配置连接的负例
     * @return void
     */
    public function testMissingConnectionThrows(): void {
        Config::set(array(
            'database'=>array(
                'connections'=>array(
                    'default'=>array('type'=>'mysql'),
                ),
            ),
        ));
        $this->expectException(ConfigException::class);
        $this->expectExceptionCode(100801);
        Db::fromConfig('log');
    }

    /**
     * 测试全量手动配置(无需配置文件中的连接)
     * @return void
     */
    public function testManualConfig(): void {
        Config::set(array('database'=>array('connections'=>array())));
        $db=Db::fromConfig('custom',array(
            'type'=>'mysql',
            'host'=>'127.0.0.1',
            'dbname'=>'manual',
        ));
        $this->assertInstanceOf(Db::class,$db);
    }

    /**
     * 测试默认方言为 MySQL
     * @return void
     */
    public function testDefaultDialectIsMysql(): void {
        $config=new ConnectionConfig();
        $this->assertInstanceOf(MysqlDialect::class,$config->createDialect());
    }

    /**
     * 测试手动绑定方言类
     * @return void
     */
    public function testCustomDialectClass(): void {
        $config=new ConnectionConfig(dialectClass: FakeDialect::class);
        $this->assertInstanceOf(FakeDialect::class,$config->createDialect());
    }

    /**
     * 测试非法方言类的负例
     * @return void
     */
    public function testInvalidDialectClassThrows(): void {
        $config=new ConnectionConfig(dialectClass: \stdClass::class);
        $this->expectException(ConfigException::class);
        $this->expectExceptionCode(100803);
        $config->createDialect();
    }

    /**
     * 测试手动绑定编译器类
     * @return void
     */
    public function testCustomCompilerClass(): void {
        Config::set(array('database'=>array('connections'=>array('default'=>array('type'=>'mysql')))));
        $db=Db::fromConfig('default',array('compiler'=>MysqlCompiler::class));
        $this->assertInstanceOf(Db::class,$db);
    }

    /**
     * 测试非法编译器类的负例
     * @return void
     */
    public function testInvalidCompilerClassThrows(): void {
        Config::set(array('database'=>array('connections'=>array('default'=>array('type'=>'mysql')))));
        $this->expectException(ConfigException::class);
        $this->expectExceptionCode(100803);
        Db::fromConfig('default',array('compiler'=>\stdClass::class));
    }

}
