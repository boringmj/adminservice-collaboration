<?php

namespace AdminService\sql;

use \PDO;
use \PDOStatement;
use \PDOException;
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
     * limlt限制
     * @var array
     */
    private array $limit;

    /**
     * order排序
     * @var array
     */
    private array $order;

    /**
     * 临时where条件
     * @var array
     */
    private array $where_temp;

    /**
     * 高级where条件
     * @var array
     */
    private array $where_ex;

    /**
     * where条件的值
     * @var array
     */
    private array $where_value;

    /**
     * 分组数据
     * @var array
     */
    private array $group;

    /**
     * 允许的操作符
     * @var array
     */
    private array $operator=array(
        '=',
        '>',
        '<',
        '>=',
        '<=',
        '!=',
        '<>',
        'LIKE',
        'NOT LIKE',
        'IN',
        // 'IS NULL',       暂未支持
        // 'IS NOT NULL',   暂未支持
        // 'BETWEEN',       暂未支持
    );

    /**
     * 重置查询状态
     * 
     * @access protected
     * @return self
     */
    public function reset(): self {
        $this->where_array=array();
        $this->where_temp=array();
        $this->limit=array();
        $this->order=array();
        $this->where_ex=array();
        $this->where_value=array();
        $this->group=array();
        $this->iterator=false;
        $this->lock='';
        $this->distinct=false;
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
            else if(is_string($key)) {
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
     * @param mixed $options 选项(辅助参数)
     * @return string
     */
    private function build(string $type,mixed $data=null,mixed $options=null): string {
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
                // // 判断是否要去重复
                // if($this->distinct)
                //     $fields_string='DISTINCT '.$fields_string;
                $sql='SELECT '.$fields_string.' FROM `'.$this->table.'`'.$this->build('where');
                // 添加分组
                $sql.=$this->build('group');
                if($options==='find')
                    return $sql;
                // 添加排序和限制
                $sql.=$this->build('order');
                $sql.=$this->build('limit');
                // 添加行锁
                $sql.=$this->build('lock');
                $sql.=';';
                return $sql;
            case 'find':
                $sql=$this->build('select',$data,'find');
                // 添加排序
                $sql.=$this->build('order');
                $sql.=' LIMIT 1';
                // 添加行锁
                $sql.=$this->build('lock');
                $sql.=';';
                return $sql;
            case 'count':
                $fields_string='*';
                // // 判断是否要去重复
                // if($this->distinct)
                //     $fields_string='DISTINCT '.$fields_string;
                $sql='SELECT COUNT('.$fields_string.') AS "__count"';
                // 判断是否有分组
                if(!empty($this->group)) {
                    // 将字段加入到查询中
                    foreach($this->group as $value)
                        $sql.=',`'.$value.'`';
                }
                $sql.=' FROM `'.$this->table.'`'.$this->build('where');
                // 添加分组
                $sql.=$this->build('group');
                // 添加行锁
                $sql.=$this->build('lock');
                $sql.=';';
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
                $sql.=$fields_string.') VALUES ('.$values_string.')';
                // 添加行锁
                $sql.=$this->build('lock');
                $sql.=';';
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
                    $fields_string.=$fields_string===''? ('`'.$key.'` = ?'):(',`'.$key.'` = ?');
                }
                if(empty($this->where_array)&&empty($this->where_temp)&&empty($this->where_ex))
                    throw new Exception('Update must have where condition.',100431);
                $sql.=$fields_string.$this->build('where');
                // 添加行锁
                $sql.=$this->build('lock');
                $sql.=';';
                return $sql;
            case 'delete':
                $sql='DELETE FROM `'.$this->table.'`';
                // 先处理where条件
                if(empty($this->where_array)&&empty($data)&&empty($this->where_ex))
                    throw new Exception('Delete must have where condition.',100432);
                $sql.=$this->build('where',true);
                // 然后使用 OR 拼接全部 id
                if(!empty($data)) {
                    if(!empty($this->where_array)||!empty($this->where_ex))
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
                // 添加行锁
                $sql.=$this->build('lock');
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
                    foreach($where as $value) {
                        // 判断操作符是否为 IN
                        if($value['operator']==='IN') {
                            $sql.='`'.$value['key'].'` '.$value['operator'].' (';
                            $in_string='';
                            foreach($value['value'] as $value2) {
                                $in_string.=$in_string===''? ('?'):(',?');
                                $this->where_value[]=$value2;
                            }
                            $sql.=$in_string.') AND ';
                            continue;
                        }
                        // 将值存入数组
                        $this->where_value[]=$value['value'];
                        $sql.='`'.$value['key'].'` '.$value['operator'].' ? AND ';
                    }
                    // 判断是否存在其他where条件
                    if(empty($this->where_ex))
                        $sql=substr($sql,0,-5);
                    else
                        $sql.=$this->build('where_ex');
                    if($data===true)
                        $sql.=')';
                }else {
                    // 判断是否存在其他where条件
                    if(empty($this->where_ex))
                        return '';
                    if($data===true)
                        $sql.='(';
                    $sql.=' WHERE ';
                    $sql.=$this->build('where_ex');
                    if($data===true)
                        $sql.=')';
                }
                return $sql;
            case 'where_ex':
                $sql='';
                // 判断是否存在其他where条件
                if(empty($data))
                    $data=$this->where_ex;
                // 判断data是否为空
                if(empty($data)) {
                    if(!empty($this->where_array)||!empty($this->where_temp))
                        return '';
                    return '';
                }
                foreach($data as $value) {
                    // 判断是否有where,有则需要继续遍历
                    if(!empty($value['where'])) {
                        $temp=$this->build('where_ex',$value['where'],$value['operator']);
                        $sql.='('.$temp.')';
                    } else {
                        // 直接构造
                        if($value['operator']==='IN') {
                            $sql.='`'.$value['key'].'` '.$value['operator'].' (';
                            $in_string='';
                            foreach($value['value'] as $value2) {
                                $in_string.=$in_string===''? ('?'):(',?');
                                $this->where_value[]=$value2;
                            }
                            $sql.=$in_string.')';
                        } else {
                            $sql.='`'.$value['key'].'` '.$value['operator'].' ?';
                            // 将值存入数组
                            $this->where_value[]=$value['value'];
                        }
                    }
                    // 如果不为最后一个元素,则添加连接符号
                    if($value!==end($data))
                        $sql.=($options==='OR'?' OR ':' AND ');
                }
                return $sql;
            case 'limit':
                $sql=' LIMIT ';
                // 判断是否为空
                if(empty($this->limit))
                    return '';
                // 取出第一个参数
                $sql.=$this->limit[0];
                // 判断是否存在第二个参数
                if(!empty($this->limit[1]))
                    $sql.=','.$this->limit[1];
                return $sql;
            case 'order':
                $sql=' ORDER BY ';
                // 判断是否为空
                if(empty($this->order))
                    return '';
                foreach($this->order as $value)
                    $sql.='`'.$value[0].'` '.$value[1].',';
                $sql=substr($sql,0,-1);
                return $sql;
            case 'lock':
                $temp_lock='';
                // 判断是否需要加行锁
                if($this->lock==='shared')
                    $temp_lock=' LOCK IN SHARE MODE';
                else if($this->lock==='update')
                    $temp_lock=' FOR UPDATE';
                return $temp_lock;
            case 'group':
                $sql=' GROUP BY ';
                // 判断是否为空
                if(empty($this->group))
                    return '';
                foreach($this->group as $value)
                    $sql.='`'.$value.'`,';
                $sql=substr($sql,0,-1);
                return $sql;
            default:
                throw new Exception('SQL not build.',100430);
        }
    }

    /**
     * 获取上一次执行的SQL语句
     * 
     * @access public
     * @return string
     */
    public function getLastSql(): string {
        return $this->lastsql??'';
    }

    /**
     * 准备sql语句
     * 
     * @access public
     * @param string $sql SQL语句
     * @return PDOStatement
     */
    public function prepare(string $sql): PDOStatement {
        $this->check_connect();
        $this->lastsql=$sql;
        try {
            return $this->db->prepare($sql);
        } catch(PDOException $e) {
            throw new Exception('SQL prepare error.',100460,array(
                'sql'=>$sql,
                'info'=>$e->getMessage(),
                'error'=>$this->db->errorInfo()
            ));
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
        $stmt=$this->prepare($sql);
        if($stmt===false)
            throw new Exception('SQL prepare error.',100420,array(
                'sql'=>$sql,
                'error'=>$this->db->errorInfo()
            ));
        // 传入where条件的值
        $i=1;
        foreach($this->where_value as $value) {
            $stmt->bindValue($i,$value);
            $i++;
        }
        if($stmt->execute()) {
            // 判断是否需要返回迭代器
            if($this->iterator) {
                return $this->iterator_select($stmt);
            } else {
                $result=$stmt->fetchAll(PDO::FETCH_ASSOC);
                $stmt->closeCursor();
                // 重置查询状态
                $this->reset();
                return $result;
            }
        }
        $this->reset();
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
        while($row=$stmt->fetch(PDO::FETCH_ASSOC))
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
        $stmt=$this->prepare($sql);
        if($stmt===false)
            throw new Exception('SQL prepare error.',100420,array(
                'sql'=>$sql,
                'error'=>$this->db->errorInfo()
            ));
        // 传入where条件的值
        $i=1;
        foreach($this->where_value as $value) {
            $stmt->bindValue($i,$value);
            $i++;
        }
        if($stmt->execute()) {
            $result=$stmt->fetch(PDO::FETCH_ASSOC);
            // 如果$fields不是数组且不为*, 则返回对应字段的值
            if((!is_array($fields)&&$fields!=='*')&&(!is_bool($result))&&isset($result[$fields]))
                $result=$result[$fields];
            $stmt->closeCursor();
            // 重置查询状态
            $this->reset();
            return $result;
        }
        $this->reset();
        throw new Exception('SQL execute error.',100406,array(
            'sql'=>$sql,
            'error'=>$stmt->errorInfo()
        ));
    }

    /**
     * 统计当前查询条件下的数据总数
     * 
     * @access public
     * @return int|array
     */
    public function count(): int|array {
        $sql=$this->build('count');
        $stmt=$this->prepare($sql);
        if($stmt===false)
            throw new Exception('SQL prepare error.',100420,array(
                'sql'=>$sql,
                'error'=>$this->db->errorInfo()
            ));
        // 传入where条件的值
        $i=1;
        foreach($this->where_value as $value) {
            $stmt->bindValue($i,$value);
            $i++;
        }
        if($stmt->execute()) {
            // 判断是否启用了group, 如果启用了group, 则返回数组, 否则返回int
            if(empty($this->group)) {
                $result=$stmt->fetchColumn();
                $stmt->closeCursor();
                // 重置查询状态
                $this->reset();
                return $result;
            } else {
                $result=$stmt->fetchAll(PDO::FETCH_ASSOC);
                $stmt->closeCursor();
                // 重置查询状态
                $this->reset();
                return $result;
            }
        }
        $this->reset();
    }

    /**
     * 插入数据
     * 
     * @access public
     * @param array ...$data 数据
     * @return int
     */
    public function insert(...$data): int {
        // 先检查传入数据是否有效
        $data=$this->format_data(...$data);
        foreach($data as $temp) {
            if(!is_array($temp))
                throw new Exception('Insert $data not is array.',100422);
            $sql=$this->build('insert',$temp);
            $stmt=$this->prepare($sql);
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
                $this->reset();
                throw new Exception('SQL execute error.',100407,array(
                    'sql'=>$sql,
                    'data'=>$temp,
                    'error'=>$stmt->errorInfo()
                ));
            }
        }
        // 获取插入了多少条数据
        $result=$stmt->rowCount();
        // 重置查询状态
        $this->reset();
        return $result;
    }

    /**
     * 更新数据
     * 
     * @access public
     * @param array ...$data 数据
     * @return int
     */
    public function update(...$data): int {
        // 先检查传入数据是否有效
        $data=$this->format_data(...$data);
        foreach($data as $temp) {
            if(!is_array($temp))
                throw new Exception('Update $data not is array.',100423);
            // 重置where条件的值
            $this->where_value=array();
            $sql=$this->build('update',$temp);
            $stmt=$this->prepare($sql);
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
            // 最后绑定where条件
            foreach($this->where_value as $value) {
                $stmt->bindValue($i,$value);
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
        // 获取更新了多少条数据
        $result=$stmt->rowCount();
        // 重置查询条件
        $this->reset();
        return $result;
    }

    /**
     * 设置limit限制
     * 
     * @access public
     * @param ...$data limit限制
     * @return self
     */
    public function limit(...$data): self {
        // 先判断传入的数据长度
        if(count($data)>2||count($data)<1)
            throw new Exception('Limit $data length error.',100429);
        // 判断传入的数据类型
        if(is_int($data[0])) {
            if(isset($data[1])&&is_int($data[1]))
                $this->limit=array($data[0],$data[1]);
            else
                $this->limit=array($data[0]);
        } else
            throw new Exception('Limit $data error.',100430);
        return $this;
    }

    /**
     * 设置group分组
     * 
     * @access public
     * @param ...$data group分组
     * @return self
     */
    public function group(...$data): self {
        // 先判断传入的数据类型
        foreach($data as $value) {
            if(is_string($value)) {
                $this->check_key($value);
                $this->group[]=$value;
            } else if(is_array($value)) {
                foreach($value as $value2) {
                    if(is_string($value2)) {
                        $this->check_key($value2);
                        $this->group[]=$value2;
                    } else
                        throw new Exception('Group $data error.',100450);
                }
            } else
                throw new Exception('Group $data error.',100451);
        }
        return $this;
    }

    /**
     * 设置order排序
     * 
     * @access public
     * @param ...$data order排序
     * @return self
     */
    public function order(...$data): self {
        // 先判断传入的数据类型
        foreach($data as $value) {
            if(is_string($value)) {
                // 判断是否可以通过空格分割为两个字符串
                $temp=explode(' ',$value);
                if(count($temp)>2)
                    throw new Exception('Order $data length error.',100431);
                // 将第一个字符左右的`去除
                $temp[0]=trim($temp[0],'`');
                // 判断第一个字符串是否为字段名
                $this->check_key($temp[0]);
                // 判断是否有第二个字符串,如果有则判断是否为 ASC 或 DESC, 如果没有则默认为 ASC
                if(isset($temp[1])) {
                    $temp[1]=strtoupper($temp[1]);
                    if($temp[1]!=='ASC'&&$temp[1]!=='DESC')
                        throw new Exception('Order $data error.',100432);
                    $this->order[]=array($temp[0],$temp[1]);
                } else
                    $this->order[]=array($temp[0],'ASC');
            } elseif(is_array($value)) {
                if(count($value)>2||count($value)<1)
                    throw new Exception('Order $data length error.',100431);
                // 将第一个字符左右的`去除
                $value[0]=trim($value[0],'`');
                // 判断第一个字符串是否为字段名
                $this->check_key($value[0]);
                // 判断是否有第二个字符串,如果有则判断是否为 ASC 或 DESC, 如果没有则默认为 ASC
                if(isset($value[1])) {
                    $value[1]=strtoupper($value[1]);
                    if($value[1]!=='ASC'&&$value[1]!=='DESC')
                        throw new Exception('Order $data error.',100432);
                    $this->order[]=array($value[0],$value[1]);
                } else
                    $this->order[]=array($value[0],'ASC');
            } else
                throw new Exception('Order $data error.',100433);
        }
        return $this;
    }

    /**
     * 删除数据
     * 
     * @access public
     * @param int|string|array|null $data 主键或者组件组
     * @return int
     */
    public function delete(int|string|array|null $data=null): int {
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
        $stmt=$this->prepare($sql);
        if($stmt===false)
            throw new Exception('SQL prepare error.',100427,array(
                'sql'=>$sql,
                'error'=>$this->db->errorInfo()
            ));
        $i=1;
        // 先绑定 where 条件
        foreach($this->where_value as $value) {
            $stmt->bindValue($i,$value);
            $i++;
        }
        // 最后绑定主键
        foreach($data_temp as $value) {
            $stmt->bindValue($i,$value);
            $i++;
        }
        if(!$stmt->execute()) {
            $this->reset();
            throw new Exception('SQL execute error.',100428,array(
                'sql'=>$sql,
                'data'=>$data_temp,
                'error'=>$stmt->errorInfo()
            ));
        }
        // 获取删除了多少条数据
        $result=$stmt->rowCount();
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
        if(!in_array($operator,$this->operator))
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
     * 高级查询
     * 
     * @access public
     * @param array ...$data 高级查询条件
     * @return self
     */
    public function whereEx(array ...$data): self {
        foreach($data as $where) {
            // 判断 $where 是否为数组
            if(!is_array($where))
                throw new Exception('SQL whereEx error.',100434,array(
                    'where'=>$where
                ));
            $this->where_ex[]=$this->whereExBuild($where);
        }
        return $this;
    }

    /**
     * 将数组递归为多层where条件
     * 
     * @access private
     * @param array $where where条件
     * @return array
     */
    public function whereExBuild(array $where): array {
        // 判断数组是否为空
        if(empty($where))
            throw new Exception('SQL whereEx error.',100436,array(
                'where'=>$where
            ));
        if(is_string($where[0])) {
            // 判断数组长度是否大于等于2
            if(count($where)<2)
            throw new Exception('SQL whereEx error.',100435,array(
                'where'=>$where
            ));
            // 第一个元素为字符串,则视为该层为最后一层,返回结果
            $operator='=';
            $value='';
            // 判断是否存在第三个参数,如果存在,则使用第三个参数作为值,如果不存在,则使用第二个参数作为值
            if(isset($where[2])) {
                $operator=strtoupper($where[1]);
                $value=$where[2];
            } else
                $value=$where[1];
            // 判断操作符是否在允许的范围内
            if(!in_array($operator,$this->operator))
                throw new Exception('SQL operator error.',100438,array(
                    'operator'=>$operator
                ));
            return array(
                'key'=>$where[0],
                'value'=>$value,
                'operator'=>$operator,
            );
        }
        // 如果第一个元素不为字符串,则取出数组的最后一个元素,判断是否为“or”,“and”(转为大写后判断)
        $last=array_pop($where);
        // 如果是字符串,则转为大写
        if(is_string($last))
            $last=strtoupper($last);
        // 判断末尾是否为数组,如果是则加回去
        if(is_array($last)) {
            $where[]=$last;
            $last='AND';
        }
        $result=array(
            'operator'=>$last==='OR'?'OR':'AND',
            'where'=>array()
        );
        foreach($where as $value) {
            // 判断是否为数组
            if(is_array($value))
                $result['where'][]=$this->whereExBuild($value);
            else
                throw new Exception('SQL whereEx error.',100437,array(
                    'where'=>$where
                ));
        }
        return $result;
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
    }

}

?>