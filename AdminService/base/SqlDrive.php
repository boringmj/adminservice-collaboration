<?php

namespace base;

use \PDO;
use AdminService\Config;
use AdminService\Exception;

/**
 * Sql驱动基类
 * 
 * @access public
 * @package base
 * @version 1.0.3
 */
abstract class SqlDrive implements Sql {

    /**
     * 数据库连接对象
     * @var PDO
     */
    protected PDO $db;

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
     * 行锁信息
     * @var string
     */
    protected string $lock;

    /**
     * 上一次执行的SQL语句
     * @var string
     */
    protected string $last_sql;

    /**
     * 是否开启distinct
     * @var bool
     */
    protected bool $distinct;

    /**
     * 检查是否已经连接数据库且是否已经开启事务
     * 
     * @access protected
     * @return void
     */
    abstract protected function check_connect(): void;

    /**
     * 开启事务
     *
     * @access public
     * @return void
     * @throws Exception
     */
    public function beginTransaction(): void {
        $this->check_connect();
        // 判断是否已经开启事务
        if($this->db->inTransaction())
            throw new Exception('Transaction has been started.',100410);
        $this->db->beginTransaction();
    }

    /**
     * 提交事务
     *
     * @access public
     * @return void
     * @throws Exception
     */
    public function commit(): void {
        $this->check_connect();
        if(!$this->db->inTransaction())
            throw new Exception('Transaction has not been started.',100411);
        $this->lock='';
        $this->db->commit();
    }

    /**
     * 回滚事务
     *
     * @access public
     * @return void
     * @throws Exception
     */
    public function rollBack(): void {
        $this->check_connect();
        if(!$this->db->inTransaction())
            throw new Exception('Transaction has not been started.',100412);
        $this->lock='';
        $this->db->rollBack();
    }

    /**
     * 构造函数
     *
     * @access public
     * @param PDO|null $db 数据库连接对象
     * @param string|null $table 数据库表名
     * @throws Exception
     */
    final public function __construct(?PDO $db=null,?string $table=null) {
        if($db!==null)
           $this->db($db);
        if($table!==null)
            $this->table($table);
        // 初始化
        $this->iterator=false;
        $this->lock='';
        $this->last_sql='';
        $this->distinct=false;
        $this->reset();
    }

    /**
     * 传入数据库连接对象
     * 
     * @access public
     * @param PDO $db 数据库连接对象
     * @return self
     */
    final public function db(PDO $db): self {
        $this->db=$db;
        $this->is_connect=true;
        $this->iterator=false;
        return $this;
    }

    /**
     * 设置数据库表名
     *
     * @access public
     * @param string|null $table 数据库表名
     * @return self
     * @throws Exception
     */
    final public function table(?string $table=null): self {
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
     * @throws Exception
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

    /**
     * 为当前语句设置显式行锁
     *
     * @access public
     * @param string $type 锁类型(shared,update且默认为update,不区分大小写,其他值无效)
     * @return self
     * @throws Exception
     */
    public function lock(string $type='update'): self {
        $this->check_connect();
        // 判断是否已经开启事务
        if(!$this->db->inTransaction())
            throw new Exception('Transaction has not been started.',100420);
        $type=strtolower($type);
        if(in_array($type,array('shared','update')))
            $this->lock=$type;
        return $this;
    }

    /**
     * 自动去重复(仅对 select 和 count 生效)
     * 
     * @access public
     * @return self
     * @deprecated
     */
    public function distinct(): self {
        $this->distinct=true;
        return $this;
    }

}