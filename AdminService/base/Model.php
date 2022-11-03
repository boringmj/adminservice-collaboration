<?php

namespace base;

use base\Database;

/**
 * 模型基类
 * 
 * @access public
 * @abstract
 * @package base
 * @version 1.0.0
 */
abstract class Model extends Database {

    /**
     * 查询数据
     * 
     * @access public
     * @param string|array $fields 查询字段(默认为*)
     * @return mixed
     */
    public function select(string|array $fields='*'): mixed {
        return $this->table($this->table_name??null)->select($fields);
    }

    /**
     * 根据条件查询数据
     * 
     * @access public
     * @param string|array $where 字段名称或者数据数组
     * @param mixed $data 查询数据
     * @param string $operator 操作符
     * @return self
     */
    public function where(string|array $where,mixed $data=null,?string $operator='='): self {
        $this->db_object->where($where,$data,$operator);
        return $this;
    }

}

?>