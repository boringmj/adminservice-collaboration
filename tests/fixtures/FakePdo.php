<?php

namespace Tests\Fixtures;

use PDO;
use PDOException;
use PDOStatement;

/**
 * 测试用假 PDO
 *
 * - 不建立真实连接, 记录调用供断言
 * - 可配置 SELECT 返回行、写语句受影响行数、执行错误、事务调用记录
 */
final class FakePdo extends PDO {

    /**
     * SELECT 语句返回的数据行
     * @var array
     */
    public array $selectRows=array();

    /**
     * 写语句受影响的行数
     * @var int
     */
    public int $affectedRows=0;

    /**
     * 设置后执行语句时抛出该错误
     * @var string|null
     */
    public ?string $error=null;

    /**
     * 已执行的 SQL 列表
     * @var array
     */
    public array $executed=array();

    /**
     * 已绑定的参数
     * @var array
     */
    public array $bound=array();

    /**
     * 事务调用记录(begin/commit/rollback)
     * @var array
     */
    public array $transactionCalls=array();

    /**
     * 无结果 SQL 调用记录(保存点等)
     * @var array
     */
    public array $execCalls=array();

    /**
     * 是否处于事务中
     * @var bool
     */
    public bool $inTransactionFlag=false;

    /**
     * 构造方法(不建立连接)
     */
    public function __construct() {
    }

    /**
     * 准备语句
     *
     * @access public
     * @param string $query SQL
     * @param array $options 选项
     * @return PDOStatement|false
     */
    public function prepare(string $query,array $options=array()): PDOStatement|false {
        $fake=$this;
        return new class($fake,$query) extends PDOStatement {

            private FakePdo $fake;

            private string $query;

            public array $bound=array();

            public function __construct(FakePdo $fake,string $query) {
                $this->fake=$fake;
                $this->query=$query;
            }

            public function bindValue(string|int $param,mixed $value,int $type=PDO::PARAM_STR): bool {
                $this->bound[$param]=$value;
                return true;
            }

            public function execute(?array $params=null): bool {
                if($this->fake->error!==null)
                    throw new PDOException($this->fake->error);
                $this->fake->executed[]=$this->query;
                // 使用 += 保留数字键, array_merge 会重排数字键
                $this->fake->bound+=$this->bound;
                return true;
            }

            public function fetchAll(int $mode=PDO::FETCH_DEFAULT,mixed ...$args): array {
                return $this->fake->selectRows;
            }

            public function fetch(int $mode=PDO::FETCH_DEFAULT,int $cursorOrientation=PDO::FETCH_ORI_NEXT,int $cursorOffset=0): mixed {
                return $this->fake->selectRows[0]??false;
            }

            public function rowCount(): int {
                return $this->fake->affectedRows;
            }

            public function closeCursor(): bool {
                return true;
            }

            public function errorInfo(): array {
                return $this->fake->error!==null?array('','',$this->fake->error):array();
            }
        };
    }

    /**
     * 开始事务
     *
     * @access public
     * @return bool
     */
    public function beginTransaction(): bool {
        $this->transactionCalls[]='begin';
        $this->inTransactionFlag=true;
        return true;
    }

    /**
     * 提交事务
     *
     * @access public
     * @return bool
     */
    public function commit(): bool {
        $this->transactionCalls[]='commit';
        $this->inTransactionFlag=false;
        return true;
    }

    /**
     * 回滚事务
     *
     * @access public
     * @return bool
     */
    public function rollBack(): bool {
        $this->transactionCalls[]='rollback';
        $this->inTransactionFlag=false;
        return true;
    }

    /**
     * 是否处于事务中
     *
     * @access public
     * @return bool
     */
    public function inTransaction(): bool {
        return $this->inTransactionFlag;
    }

    /**
     * 执行无结果 SQL
     *
     * @access public
     * @param string $statement SQL
     * @return int|false
     */
    public function exec(string $statement): int|false {
        $this->execCalls[]=$statement;
        return 0;
    }

    /**
     * 获取最后插入的 ID
     *
     * @access public
     * @param string|null $name 序列名称
     * @return string|false
     */
    public function lastInsertId(?string $name=null): string|false {
        return '1';
    }

}
