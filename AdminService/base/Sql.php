<?php

namespace base;

interface Sql {

    /**
     * 构造函数
     * 
     * @access public
     * @param \PDO $db 数据库连接对象
     * @param string $table 数据库表名
     */
    public function __construct(?\PDO $db=null,?string $table=null);

    /**
     * 传入数据库连接对象
     * 
     * @access public
     * @param \PDO $db 数据库连接对象
     * @return self
     */
    public function db(\PDO $db): self;

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
     * @param string|array $where 查询条件
     * @return self
     */
    public function where(string|array $where): self;

    /**
     * 设置数据库表名
     * 
     * @access public
     * @param string $table 数据库表名
     * @return self
     */
    public function table(string $table): self;

    /**
     * 插入数据
     * 
     * @access public
     * @param array ...$data 数据
     * @return bool
     */
    public function insert(...$data): bool;

    /**
     * 更新数据
     * 
     * @access public
     * @param array ...$data 数据
     * @return bool
     */
    public function update(...$data): bool;

    /**
     * 删除数据
     * 
     * @access public
     * @param int|string|array|null $data 主键或者组件组
     * @return bool
     */
    public function delete(int|string|array|null $data=null): bool;

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
     * 重置查询状态
     * 
     * @access protected
     * @return self
     */
    public function reset(): self;

}

?>