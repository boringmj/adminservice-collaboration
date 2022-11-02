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

}