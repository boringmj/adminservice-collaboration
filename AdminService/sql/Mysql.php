<?php

namespace AdminService\sql;

use base\SqlDrive;
use AdminService\Exception;

/**
 * Mysql 驱动类
 * 
 * @access public
 * @package sql
 * @version 1.0.2
 */
final class Mysql extends SqlDrive {

    /**
     * where条件
     * @var array
     */
    private array $where_array;

    /**
     * 临时where条件
     * @var array
     */
    private array $where_temp;

    /**
     * 重置查询状态
     * 
     * @access protected
     * @return self
     */
    public function reset(): self {
        $this->where_array=array();
        $this->where_temp=array();
        $this->iterator=false;
        return $this;
    }

    /**
     * 将各种数据格式转换为指定格式
     * 
     * @access private
     * @param mixed ...$data 数据
     * @return array
     */
    private function format_data(...$data): array {
        $data_temp=[];
        foreach($data as $key=>$value) {
            if(is_int($key)&&is_array($value))
                $data_temp[]=$value;
            else if (is_string($key)) {
                if(isset($data_temp[0]))
                    $data_temp[0]=array();
                $data_temp[0][$key]=$value;
            } else
                throw new Exception('invalid $data.',100409);
        }
        return $data_temp;
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
        $this->check_connect();
        switch($type) {
            case 'select':
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
                $sql='SELECT '.$fields_string.' FROM `'.$this->table.'`'.$this->build('where');
                $sql.=';';
                return $sql;
            case 'find':
                $sql=$this->build('select',$data);
                $sql=substr($sql,0,-1);
                $sql.=' LIMIT 1;';
                return $sql;
            case 'insert':
                $sql='INSERT INTO `'.$this->table.'` (';
                $fields_string='';
                $values_string='';
                $i=1;
                foreach($data as $key=>$value) {
                    $this->check_key($key);
                    $fields_string.=$fields_string===''? ('`'.$key.'`'):(',`'.$key.'`');
                    $values_string.=$values_string===''? ('?'):(',?');
                    $i++;
                }
                $sql.=$fields_string.') VALUES ('.$values_string.');';
                return $sql;
            case 'update':
                $sql='UPDATE `'.$this->table.'` SET ';
                $fields_string='';
                foreach($data as $key=>$value) {
                    // 这里的 id 是主键, 主键是不需要更新的, 而且需要将主键加入到 where 条件中
                    if($key==='id') {
                        $this->where_temp[]=array(
                            'key'=>$key,
                            'value'=>$value,
                            'operator'=>'='
                        );
                        continue;
                    }
                    $this->check_key($key);
                    $fields_string.=$fields_string===''? ('`'.$key.'`=?'):(',`'.$key.'`=?');
                }
                if(empty($this->where_array)&&empty($this->where_temp))
                    throw new Exception('Update must have where condition.',100431);
                $sql.=$fields_string.$this->build('where').';';
                return $sql;
            case 'delete':
                $sql='DELETE FROM `'.$this->table.'`';
                // 先处理where条件
                if(empty($this->where_array)&&empty($data))
                    throw new Exception('Delete must have where condition.',100432);
                $sql.=$this->build('where',true);
                // 然后使用 OR 拼接全部 id
                if(!empty($data)) {
                    if(!empty($this->where_array))
                        $sql.=' OR ';
                    else
                        $sql.=' WHERE ';
                    $sql.='(`id` IN (';
                    $id_string='';
                    foreach($data as $value) {
                        $id_string.=$id_string===''? ('?'):(',?');
                    }
                    $sql.=$id_string.'))';
                }
                $sql.=';';
                return $sql;
            case 'where':
                $sql='';
                // 合并临时where条件, 如果冲突则保留临时条件
                $where=array_merge($this->where_array,$this->where_temp);
                if(!empty($where)) {
                    $sql.=' WHERE ';
                    if($data===true)
                        $sql.='(';
                    foreach($where as $value)
                        $sql.='`'.$value['key'].'`'.$value['operator'].'? AND ';
                    $sql=substr($sql,0,-5);
                    if($data===true)
                        $sql.=')';
                }
                return $sql;
            default:
                throw new Exception('SQL not build.',100430);
        }
    }

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
            throw new Exception('SQL prepare error.',100420,array(
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
            // 判断是否需要返回迭代器
            if($this->iterator) {
                return $this->iterator_select($stmt);
            } else {
                $result=$stmt->fetchAll(\PDO::FETCH_ASSOC);
                $stmt->closeCursor();
                // 重置查询状态
                $this->reset();
                return $result;
            }
        }
        throw new Exception('SQL execute error.',100406,array(
            'sql'=>$sql,
            'error'=>$stmt->errorInfo()
        ));
    }

    /**
     * 通过迭代器查询数据
     * 
     * @access public
     * @param object $stmt PDOStatement对象
     * @return mixed
     */
    private function iterator_select(object $stmt): mixed {
        $this->iterator=false;
        // 逐条读取数据
        while($row=$stmt->fetch(\PDO::FETCH_ASSOC))
            yield $row;
        $stmt->closeCursor();
        // 重置查询状态
        $this->reset();
    }

    /**
     * 查询一条数据
     * 
     * @access public
     * @param string|array $fields 查询字段
     * @return mixed
     */
    public function find(string|array $fields='*'): mixed {
        $sql=$this->build('find',$fields);
        $stmt=$this->db->prepare($sql);
        if($stmt===false)
            throw new Exception('SQL prepare error.',100420,array(
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
            $result=$stmt->fetch(\PDO::FETCH_ASSOC);
            // 如果$fields不是数组且不为*, 则返回对应字段的值
            if((!is_array($fields)&&$fields!=='*')&&(!is_bool($result))&&isset($result[$fields]))
                $result=$result[$fields];
            $stmt->closeCursor();
            // 重置查询状态
            $this->reset();
            return $result;
        }
        throw new Exception('SQL execute error.',100406,array(
            'sql'=>$sql,
            'error'=>$stmt->errorInfo()
        ));
    }

    /**
     * 插入数据
     * 
     * @access public
     * @param array ...$data 数据
     * @return bool
     */
    public function insert(...$data): bool {
        $result=true;
        // 先检查传入数据是否有效
        $data=$this->format_data(...$data);
        foreach($data as $temp) {
            if(!is_array($temp))
                throw new Exception('Insert $data not is array.',100422);
            $sql=$this->build('insert',$temp);
            $stmt=$this->db->prepare($sql);
            if($stmt===false)
                throw new Exception('SQL prepare error.',100421,array(
                    'sql'=>$sql,
                    'error'=>$this->db->errorInfo()
                ));
            $i=1;
            foreach($temp as $value) {
                $stmt->bindValue($i,$value);
                $i++;
            }
            if(!$stmt->execute()) {
                $result=false;
                throw new Exception('SQL execute error.',100407,array(
                    'sql'=>$sql,
                    'data'=>$temp,
                    'error'=>$stmt->errorInfo()
                ));
            }
        }
        // 重置查询状态
        $this->reset();
        return $result;
    }

    /**
     * 更新数据
     * 
     * @access public
     * @param array ...$data 数据
     * @return bool
     */
    public function update(...$data): bool {
        $result=true;
        // 先检查传入数据是否有效
        $data=$this->format_data(...$data);
        foreach($data as $temp) {
            if(!is_array($temp))
                throw new Exception('Update $data not is array.',100423);
            $sql=$this->build('update',$temp);
            $stmt=$this->db->prepare($sql);
            if($stmt===false)
                throw new Exception('SQL prepare error.',100424,array(
                    'sql'=>$sql,
                    'error'=>$this->db->errorInfo()
                ));
            $i=1;
            // 先绑定更新数据
            foreach($temp as $key=>$value) {
                // 这里的 id 是主键, 主键是不需要更新的, 而且需要将主键加入到 where 条件中
                if($key==='id')
                    continue;
                $stmt->bindValue($i,$value);
                $i++;
            }
            // 再绑定where条件, 合并临时where条件, 如果冲突则保留临时条件
            $where=array_merge($this->where_array,$this->where_temp);
            foreach($where as $value) {
                $stmt->bindValue($i,$value['value']);
                $i++;
            }
            if(!$stmt->execute()) {
                // 重置查询条件
                $this->reset();
                throw new Exception('SQL execute error.',100408,array(
                    'sql'=>$sql,
                    'data'=>$temp,
                    'error'=>$stmt->errorInfo()
                ));
            }
        }
        // 重置查询条件
        $this->reset();
        return $result;
    }

    /**
     * 删除数据
     * 
     * @access public
     * @param int|string|array|null $data 主键或者组件组
     * @return bool
     */
    public function delete(int|string|array|null $data=null): bool {
        $result=true;
        $data_temp=array();
        // 先判断传入的数据类型
        if(is_array($data)) {
            foreach($data as $value) {
                if(is_int($value)||is_string($value))
                    $data_temp[]=$value;
                else
                    throw new Exception('Delete $data value not is int or string.',100425);
            }
        } else if(is_int($data)||is_string($data))
            $data_temp[]=$data;
        else if($data!==null)
            throw new Exception('Delete $data not is int, array, null or string.',100426);
        $sql=$this->build('delete',$data_temp);
        $stmt=$this->db->prepare($sql);
        if($stmt===false)
            throw new Exception('SQL prepare error.',100427,array(
                'sql'=>$sql,
                'error'=>$this->db->errorInfo()
            ));
        $i=1;
        // 先绑定 where 条件
        foreach($this->where_array as $value) {
            $stmt->bindValue($i,$value['value']);
            $i++;
        }
        // 再绑定主键
        foreach($data_temp as $value) {
            $stmt->bindValue($i,$value);
            $i++;
        }
        if(!$stmt->execute()) {
            $result=false;
            throw new Exception('SQL execute error.',100428,array(
                'sql'=>$sql,
                'data'=>$data_temp,
                'error'=>$stmt->errorInfo()
            ));
        }
        // 重置查询状态
        $this->reset();
        return $result;
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
        if(!in_array($operator,array('=','>','<','>=','<=','!=','LIKE','NOT LIKE')))
            throw new Exception('SQL operator error.',100406,array(
                'operator'=>$operator
            ));
        if(is_array($where)) {
            // 如果传入的 $where 是数组则忽略 $data
            foreach($where as $key=>$value) {
                // 先判断 $key 是否为数字, 且 $value 是否为数组
                if(is_int($key)&&is_array($value)) {
                    $key_tmp=$value['key']??$value[0]??null;
                    $this->check_key($key_tmp);
                    if($key_tmp!==null) {
                        $this->where_array[]=array(
                            'key'=>$key_tmp,
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
                    $this->where_array[]=array(
                        'key'=>$key,
                        'value'=>$value['value']??$value[0]??null,
                        'operator'=>$value['operator']??$value[1]??$operator
                    );
                else
                    $this->where_array[]=array(
                        'key'=>$key,
                        'value'=>$value,
                        'operator'=>$operator
                    );
            }
        } else {
            // 如果传入的 $where 是字符串则使用 $data
            $this->check_key($where);
            $this->where_array[]=array(
                'key'=>$where,
                'value'=>$data,
                'operator'=>$operator
            );
        }
        return $this;
    }

    /**
     * 检查是否已经连接数据库
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
        // 判断where_array是否已经初始化
        if(empty($this->where_array))
            $this->where_array=array();
        // 判断where_temp是否已经初始化
        if(empty($this->where_temp))
            $this->where_temp=array();
    }

}

?>