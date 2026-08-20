<?php

namespace Tests;

use PHPUnit\Framework\TestCase;

use app\demo\controller\DatabaseDemo;
use base\Database\Connection\PdoConnectionManager;
use base\Database\Connection\PdoConnectionPool;
use base\Database\Connection\PdoConnectionSession;
use base\Database\Db;
use base\Database\Sql\Dialect\MysqlDialect;
use Tests\Fixtures\FakePdo;

/**
 * 数据库使用示例测试
 *
 * - 用 FakePdo 跑完整示例链, 验证示例生成的 SQL 与结果结构
 */
class DatabaseDemoTest extends TestCase {

    /**
     * 创建基于 FakePdo 的数据库入口
     *
     * @access private
     * @param FakePdo $pdo 假连接
     * @return Db
     */
    private function createDb(FakePdo $pdo): Db {
        $factory=function() use ($pdo) {
            return new PdoConnectionSession($pdo,new MysqlDialect());
        };
        $manager=new PdoConnectionManager(new PdoConnectionPool($factory),$factory);
        return new Db($manager);
    }

    /**
     * 测试完整示例链
     * @return void
     */
    public function testDemo(): void {
        $pdo=new FakePdo();
        $pdo->selectRows=[['id'=>1,'name'=>'张三','age'=>20,'status'=>1]];
        $pdo->affectedRows=1;
        $data=DatabaseDemo::runDemo($this->createDb($pdo));

        // 查询
        $this->assertSame('SELECT * FROM `users`',$data['select_all']['sql']);
        $this->assertSame('SELECT `id`, `name` FROM `users`',$data['select_fields']['sql']);
        $this->assertSame(
            'SELECT `u`.`id`, `u`.`name` AS `nickname` FROM `users` `u`',
            $data['select_alias']['sql']
        );
        $this->assertSame('SELECT DISTINCT `status` FROM `users`',$data['distinct']['sql']);
        $this->assertSame('SELECT * FROM `users` ORDER BY `id` DESC',$data['order']['sql']);
        $this->assertSame('SELECT * FROM `users` LIMIT 10 OFFSET 20',$data['limit_offset']['sql']);

        // 条件
        $this->assertSame('SELECT * FROM `users` WHERE `status` IN (?, ?, ?)',$data['where_in']['sql']);
        $this->assertSame('SELECT * FROM `users` WHERE `age` BETWEEN ? AND ?',$data['where_between']['sql']);
        $this->assertSame('SELECT * FROM `users` WHERE `deleted_at` IS NOT NULL',$data['where_not_null']['sql']);
        $this->assertSame(
            'SELECT * FROM `users` WHERE `status` = ? AND (`age` < ? OR `name` LIKE ?)',
            $data['where_group']['sql']
        );

        // 单条查询
        $this->assertSame('SELECT * FROM `users` WHERE `id` = ? LIMIT 1',$data['find']['sql']);

        // 写入
        $this->assertSame(
            'INSERT INTO `users` (`name`, `age`, `status`) VALUES (?, ?, ?), (?, ?, ?)',
            $data['insert_multi']['sql']
        );
        $this->assertSame(
            'UPDATE `users` SET `name` = ?, `age` = ? WHERE `id` = ?',
            $data['update']['sql']
        );
        $this->assertSame('DELETE FROM `users` WHERE `id` IN (?, ?)',$data['delete_by_id']['sql']);

        // 聚合
        $this->assertSame('SELECT COUNT(*) AS `__count` FROM `users`',$data['count']['sql']);
        $this->assertSame(
            'SELECT COUNT(DISTINCT `status`) AS `__count` FROM `users`',
            $data['count_distinct']['sql']
        );
        $this->assertSame(
            'SELECT COUNT(*) AS `__count`, `status` FROM `users` GROUP BY `status`',
            $data['count_group']['sql']
        );

        // 关联
        $this->assertSame(
            'SELECT `u`.`id`, `o`.`id` AS `order_id` FROM `users` `u` LEFT JOIN `orders` `o` ON `u`.`id` = `o`.`user_id`',
            $data['join']['sql']
        );

        // 行锁
        $this->assertSame('SELECT * FROM `users` WHERE `id` = ? FOR UPDATE',$data['lock']['sql']);
        $this->assertSame(
            'SELECT * FROM `users` WHERE `id` = ? LOCK IN SHARE MODE',
            $data['lock_shared']['sql']
        );

        // 事务
        $this->assertSame('success',$data['transaction']);
        $this->assertSame('success',$data['transaction_nested']);
        $this->assertSame('committed',$data['transaction_manual']);

        // 结果集遍历
        $this->assertSame(1,$data['result_count']);
        $this->assertSame([1],$data['result_iterate']);

        // 错误处理(假连接不模拟错误, 视为成功)
        $this->assertTrue($data['error_handling']['success']);
        $this->assertSame('SELECT * FROM `not_exist_table`',$data['error_handling']['sql']);
    }

}
