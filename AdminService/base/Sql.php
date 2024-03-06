<?php

namespace base;

use \PDO;

interface Sql {

    /**
     * 构造函数
     *
     * @access public
     * @param PDO|null $db 数据库连接对象
     * @param string|null $table 数据库表名
     */
    public function __construct(?PDO $db=null,?string $table=null);

    /**
     * 传入数据库连接对象
     * 
     * @access public
     * @param PDO $db 数据库连接对象
     * @return self
     */
    public function db(PDO $db): self;

    /**
     * 查询数据
     * 
     * @access public
     * @param string|array $fields 查询字段
     * @return mixed
     */
    public function select(string|array $fields='*'): mixed;

    /**
     * 查询一条数据
     * 
     * @access public
     * @param string|array $fields 查询字段
     * @return mixed
     */
    public function find(string|array $fields='*'): mixed;

     /**
     * 根据条件查询数据
     *
     * @access public
     * @param string|array $where 字段名称或者数据数组
     * @param mixed $data 查询数据
     * @param string $operator 操作符
     * @return self
     * @throws Exception
     */
    public function where(string|array $where,mixed $data=null,string $operator='='): self;

    /**
     * 高级查询
     * 
     * @access public
     * @param array ...$data 高级查询条件
     * @return self
     */
    public function whereEx(array ...$data): self;

    /**
     * 设置数据库表名
     * 
     * @access public
     * @param string $table 数据库表名
     * @return self
     */
    public function table(string $table): self;

    /**
     * 获取上一次执行的SQL语句
     * 
     * @access public
     * @return string
     */
    public function getLastSql(): string;

    /**
     * 插入数据
     * 
     * @access public
     * @param array ...$data 数据
     * @return int
     */
    public function insert(array ...$data): int;

    /**
     * 更新数据
     * 
     * @access public
     * @param array ...$data 数据
     * @return int
     */
    public function update(array ...$data): int;

    /**
     * 设置limit限制
     * 
     * @access public
     * @param array|int ...$data limit限制
     * @return self
     */
    public function limit(array|int ...$data): self;

    /**
     * 设置order排序
     * 
     * @access public
     * @param array|string ...$data order排序
     * @return self
     */
    public function order(array|string ...$data): self;

    /**
     * 设置group分组(仅对 select, find 和 count 生效)
     * 
     * @access public
     * @param array|string ...$data group分组
     * @return self
     */
    public function group(array|string ...$data): self;

    /**
     * 删除数据
     * 
     * @access public
     * @param int|string|array|null $data 主键或者组件组
     * @return int
     */
    public function delete(int|string|array|null $data=null): int;

    /**
     * 开启事务
     * 
     * @access public
     * @return void
     */
    public function beginTransaction(): void;

    /**
     * 提交事务
     * 
     * @access public
     * @return void
     */
    public function commit(): void;

    /**
     * 回滚事务
     * 
     * @access public
     * @return void
     */
    public function rollBack(): void;

    /**
     * 设置下一次返回数据为迭代器(仅对 select 生效)
     * 
     * @access public
     * @return self
     */
    public function iterator(): self;

    /**
     * 统计当前查询条件下的数据总数
     * 
     * @access public
     * @return int|array
     */
    public function count(): int|array;

    /**
     * 自动去重复(仅对 select 和 count 生效)
     * 
     * @access public
     * @return self
     */
    public function distinct(): self;

    /**
     * 重置查询状态
     * 
     * @access protected
     * @return self
     */
    public function reset(): self;

    /**
     * 为当前语句设置显式行锁
     * 
     * @access public
     * @param string $type 锁类型(shared,update且默认为update,不区分大小写,其他值无效)
     * @return self
     */
    public function lock(string $type='update'): self;

}

