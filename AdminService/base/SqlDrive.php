<?php

namespace base;

use base\Sql;
use AdminService\Exception;
use AdminService\Config;

/**
 * Sql驱动基类
 * 
 * @access public
 * @package base
 * @version 1.0.1
 */
abstract class SqlDrive implements Sql {

    /**
     * 数据库连接对象
     * @var \PDO
     */
    protected \PDO $db;

    /**
     * 是否已经连接数据库
     */
    protected bool $is_connect;

    /**
     * 数据库表名
     * @var string
     */
    protected string $table;

     /**
     * 是否以迭代器形式返回
     * @var bool
     */
    protected bool $iterator;

    /**
     * 查询数据
     * 
     * @access public
     * @param string|array $fields 查询字段
     * @return mixed
     */
    abstract public function select(string|array $fields='*'): mixed;

    /**
     * 查询一条数据
     * 
     * @access public
     * @param string|array $fields 查询字段
     * @return mixed
     */
    abstract public function find(string|array $fields='*'): mixed;

    /**
     * 检查是否已经连接数据库且是否已经开启事务
     * 
     * @access protected
     * @return void
     */
    abstract protected function check_connect(): void;

    /**
     * 根据条件查询数据
     * 
     * @access public
     * @param string|array $where 查询条件
     * @return self
     */
    abstract public function where(string|array $where,mixed $data=null,?string $operator='='): self;

    /**
     * 插入数据
     * 
     * @access public
     * @param array ...$data 数据
     * @return bool
     */
    abstract public function insert(...$data): bool;

    /**
     * 更新数据
     * 
     * @access public
     * @param array ...$data 数据
     * @return bool
     */
    abstract public function update(...$data): bool;

    /**
     * 删除数据
     * 
     * @access public
     * @param int|string|array|null $data 主键或者组件组
     * @return bool
     */
    abstract public function delete(int|string|array|null $data=null): bool;

    /**
     * 重置查询状态
     * 
     * @access protected
     * @return self
     */
    abstract public function reset(): self;

    /**
     * 开启事务
     * 
     * @access public
     * @return void
     */
    public function beginTransaction(): void {
        $this->check_connect();
        // 判断是否已经开启事务
        if ($this->db->inTransaction())
            throw new Exception('Transaction has been started.',100410);
        $this->db->beginTransaction();
    }

    /**
     * 提交事务
     * 
     * @access public
     * @return void
     */
    public function commit(): void {
        $this->check_connect();
        if(!$this->db->inTransaction())
            throw new Exception('Transaction has not been started.',100411);
        $this->db->commit();
    }

    /**
     * 回滚事务
     * 
     * @access public
     * @return void
     */
    public function rollBack(): void {
        $this->check_connect();
        if(!$this->db->inTransaction())
            throw new Exception('Transaction has not been started.',100412);
        $this->db->rollBack();
    }

    /**
     * 构造函数
     * 
     * @access public
     * @param \PDO $db 数据库连接对象
     * @param string $table 数据库表名
     */
    final public function __construct(?\PDO $db=null,?string $table=null) {
        if($db!==null)
           $this->db($db);
        if($table!==null)
            $this->table($table);
    }

    /**
     * 传入数据库连接对象
     * 
     * @access public
     * @param \PDO $db 数据库连接对象
     * @return self
     */
    final public function db(\PDO $db): self {
        $this->db=$db;
        $this->is_connect=true;
        $this->iterator=false;
        return $this;
    }

    /**
     * 设置数据库表名
     * 
     * @access public
     * @param string $table 数据库表名
     * @return self
     */
    final public function table(string $table=null): self {
        if($table===null)
            return $this;
        $rule=Config::get('database.rule.table');
        if(!preg_match($rule,$table))
            throw new Exception('Table name is not valid.',100403,array(
                'table'=>$table,
                'rule'=>$rule
            ));
        $this->table=$table;
        return $this;
    }

    /**
     * 检查键值是合法
     * 
     * @access protected
     * @param string $key 键值
     * @return void
     */
    protected function check_key(string $key): void {
        $rule=Config::get('database.rule.fields');
        if(preg_match($rule,$key))
            return;
        throw new Exception('Field is illegal.',100402,array(
            'field'=>$key,
            'rule'=>$rule
        ));
    }

    /**
     * 设置下一次返回数据为迭代器(仅对 select 生效)
     * 
     * @access public
     * @return self
     */
    public function iterator(): self {
        $this->iterator=true;
        return $this;
    }

}

?>