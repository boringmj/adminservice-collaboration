<?php

namespace AdminService\sql;

use base\Sql;

/**
 * Mysql数据库操作类(Mysql 驱动)
 * 
 * @access public
 * @package sql
 * @version 1.0.0
 */
final class Mysql implements Sql {

    /**
     * 数据库连接对象
     * @var \PDO
     */
    protected \PDO $db;

    /**
     * 构造函数
     * 
     * @access public
     * @param \PDO $db 数据库连接对象
     */
    public function __construct(?\PDO $db=null) {
       if($db!==null)
           $this->db=$db;
    }

    /**
     * 传入数据库连接对象
     * 
     * @access public
     * @param \PDO $db 数据库连接对象
     * @return self
     */
    public function db(\PDO $db): self {
        // 未完成
        $this->db=$db;
        return $this;
    }

    /**
     * 查询数据
     * 
     * @access public
     * @param string|array $fields 查询字段
     * @return mixed
     */
    public function select(string|array $fields='*'): mixed {
        // 未完成
        return $fields;
    }

    /**
     * 根据条件查询数据
     * 
     * @access public
     * @param string|array $where 查询条件
     * @return self
     */
    public function where(string|array $where): self {
        return $this;
    }

}

?>