<?php

namespace AdminService\sql;

use base\SqlDrive;
use AdminService\Exception;
use AdminService\Config;

/**
 * Mysql 驱动类
 * 
 * @access public
 * @package sql
 * @version 1.0.0
 */
final class Mysql extends SqlDrive {

    /**
     * where条件
     * @var array
     */
    private array $where_array;

    /**
     * 查询数据
     * 
     * @access public
     * @param string|array $fields 查询字段(默认为*)
     * @return mixed
     */
    public function select(string|array $fields='*'): mixed {
        $sql=$this->build('select',$fields);
        $stmt=$this->db->prepare($sql);
        if($stmt===false)
            throw new Exception('SQL prepare error.',100405,array(
                'sql'=>$sql,
                'error'=>$this->db->errorInfo()
            ));
        $i=1;
        foreach($this->where_array as $value) {
            $stmt->bindValue($i,$value['value']);
            $i++;
        }
        if($stmt->execute())
        {
            $result=$stmt->fetchAll(\PDO::FETCH_ASSOC);
            $stmt->closeCursor();
            $this->where_array=array();
            return $result;
        }
        throw new Exception('SQL execute error.',100406,array(
            'sql'=>$sql,
            'error'=>$stmt->errorInfo()
        ));
    }

    /**
     * 构造SQl语句
     * 
     * @access private
     * @param string $type SQL类型
     * @param mixed $data 数据
     * @return string
     */
    private function build(string $type,mixed $data=null): string {
        if($type==='select') {
            $fields=$data;
            $fields_string='';
            if(is_array($fields)) {
                foreach($fields as $value) {
                    $this->check_key($value);
                    $fields_string.=$fields_string===''? ('`'.$value.'`'):(',`'.$value.'`');
                }
            } else {
                if($fields==='*')
                    $fields_string='*';
                else {
                    $this->check_key($fields);
                    $fields_string='`'.$fields.'`';
                }
            }
            $this->check_connect();
            $sql='SELECT '.$fields_string.' FROM '.$this->table;
            if(!empty($this->where_array)) {
                $sql.=' WHERE ';
                foreach($this->where_array as $key=>$value) {
                    $sql.='`'.$key.'` '.$value['operator'].' ? AND ';
                }
                $sql=substr($sql,0,-4);
            }
            $sql.=';';
            return $sql;
        }
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
        $this->check_connect();
        // 判断$options是在允许的范围内
        $operator=strtoupper($operator);
        if(!in_array($operator,array('=','>','<','>=','<=','!=','LIKE','NOT LIKE','IN','NOT IN','BETWEEN','NOT BETWEEN')))
            throw new Exception('SQL operator error.',100406,array(
                'operator'=>$operator
            ));
        if(is_array($where)) {
            // 如果传入的 $where 是数组则忽略 $data
            foreach($where as $key=>$value) {
                // 先判断 $key 是否为数字, 如果是数字则 $value 必须符合数组格式
                if(is_int($key)) {
                    $key=$value['key']??$value[0]??null;
                    if($key!==null) {
                        $this->where_array[$value[0]]=array(
                            'value'=>$value['value']??$value[1]??null,
                            'operator'=>$value['operator']??$value[2]??$operator
                        );
                    } else {
                        throw new Exception('SQL where error.',100407,array(
                            'where'=>$where
                        ));
                    }
                    continue;
                }
                $this->check_key($key);
                // 这里还需要判断 $value 是否是数组
                if(is_array($value))
                    $this->where_array[$key]=array(
                        'value'=>$value['value']??$value[0]??null,
                        'operator'=>$value['operator']??$value[1]??$operator
                    );
                else
                    $this->where_array[$key]=array(
                        'value'=>$value,
                        'operator'=>$operator
                    );
            }
        } else {
            // 如果传入的 $where 是字符串则使用 $data
            $this->check_key($where);
            $this->where_array[$where]=array(
                'value'=>$data,
                'operator'=>$operator
            );
        }
        return $this;
    }

    /**
     * 检查是否已经连接数据库且是否已经开启事务
     * 
     * @access protected
     * @return void
     */
    protected function check_connect(): void {
        // 检查是否已经连接数据库
        if(!$this->is_connect)
            throw new Exception('Database is not connected.',100401);
        // 检查是否已经传递了数据库表名
        if($this->table===null)
            throw new Exception('Database table name is not set, please use table() to set.',100404);
        // 判断是否在事务中,如果不在事务中则开启事务
        if(!$this->db->inTransaction())
            $this->db->beginTransaction();
        // 判断where是否已经初始化
        if(empty($this->where_array))
            $this->where_array=array();
    }

}

?>