<?php

namespace AdminService\sql;

use Generator;
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
 * @version 1.0.6
 */
final class Mysql extends SqlDrive {

    /**
     * where条件
     * @var array
     */
    private array $where_array;

    /**
     * limit限制
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
     * 过滤字段
     * @var array
     */
    protected array $filter;

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
     * 关联查询
     * @var array
     */
    private array $join=array();

    /**
     * 关联查询支持的类型
     * @var array
     */
    private array $join_type=array(
        'left'=>'LEFT JOIN',
        'right'=>'RIGHT JOIN',
        'inner'=>'INNER JOIN',
        'full'=>'FULL JOIN'
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
        $this->filter=array();
        $this->table[1]='';
        $this->join=array();
        return $this;
    }

    /**
     * 将各种数据格式转换为指定格式
     * 
     * @access private
     * @param mixed ...$data 数据
     * @return array
     * @throws Exception
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
     * @throws Exception
     */
    private function build(string $type,mixed $data=null,mixed $options=null): string {
        $this->check_connect();
        switch($type) {
            case 'filter':
                $fields=$this->filter;
                $fields_string='';
                // 如果为空则返回全部字段
                if(empty($fields))
                    return '*';
                foreach($fields as $value) {
                    if(count($value[0])===2)
                        $fields_string.='`'.$value[0][0].'`.`'.$value[0][1].'`';
                    else
                        $fields_string.='`'.$value[0][0].'`';
                    if(!empty($value[1]))
                        $fields_string.=' AS `'.$value[1].'`';
                    $fields_string.=',';
                }
                return substr($fields_string,0,-1);
            case 'select':
                $fields_string=$this->build('filter');
                $sql='SELECT '.$fields_string.' FROM '.$this->getTableName().$this->build('join');
                $sql.=$this->build('where');
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
                $fields_string=$this->build('filter');
                $sql='SELECT COUNT('.$fields_string.') AS "__count"';
                // 判断是否有分组
                if(!empty($this->group)) {
                    // 将字段加入到查询中
                    foreach($this->group as $value) {
                        // 判断value是否为数组
                        if(is_array($value)) {
                            if(count($value)===1)
                                $value=$value[0];
                            elseif(count($value)===2) {
                                $value=$value[0].'`.`'.$value[1];
                            } else
                                throw new Exception('Group error.',100450);
                        }
                        $sql.=',`'.$value.'`';
                    }
                }
                $sql.=' FROM '.$this->getTableName().$this->build('join').$this->build('where');
                // 添加分组
                $sql.=$this->build('group');
                // 添加行锁
                $sql.=$this->build('lock');
                $sql.=';';
                return $sql;
            case 'insert':
                $sql='INSERT INTO '.$this->getTableName().' (';
                $fields_string='';
                $values_string='';
                foreach($data as $key=>$value) {
                    $this->check_key($key);
                    $fields_string.=$fields_string===''?('`'.$key.'`'):(',`'.$key.'`');
                    $values_string.=$values_string===''?('?'):(',?');
                }
                $sql.=$fields_string.') VALUES ('.$values_string.')';
                // 添加行锁
                $sql.=$this->build('lock');
                $sql.=';';
                return $sql;
            case 'update':
                $sql='UPDATE '.$this->getTableName().' SET ';
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
                    $fields_string.=$fields_string===''?('`'.$key.'` = ?'):(',`'.$key.'` = ?');
                }
                if(empty($this->where_array)&&empty($this->where_temp)&&empty($this->where_ex))
                    throw new Exception('Update must have where condition.',100431);
                $sql.=$fields_string.$this->build('where');
                // 添加行锁
                $sql.=$this->build('lock');
                $sql.=';';
                return $sql;
            case 'delete':
                $sql='DELETE FROM '.$this->getTableName();
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
                    foreach($data as $ignored)
                        $id_string.=$id_string===''?('?'):(',?');
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
                        // 判断key是否为数组
                        if(is_array($value['key'])) {
                            if(count($value['key'])===1)
                                $value['key']=$value['key'][0];
                            elseif(count($value['key'])===2) {
                                $value['key']=$value['key'][0].'`.`'.$value['key'][1];
                            } else
                                throw new Exception('Where error.',100420);
                        }
                        // 判断操作符是否为 IN
                        if($value['operator']==='IN') {
                            $sql.='`'.$value['key'].'` '.$value['operator'].' (';
                            $in_string='';
                            foreach($value['value'] as $value2) {
                                $in_string.=$in_string===''?('?'):(',?');
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
                }else{
                    // 判断是否存在其他where条件
                    if(empty($this->where_ex))
                        return '';
                    if($data===true)
                        $sql.='(';
                    $sql.=' WHERE ';
                    $sql.=$this->build('where_ex');
                }
                if($data===true)
                    $sql.=')';
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
                $len=count($data);
                $count=0;
                foreach($data as $value) {
                    // 判断是否有where,有则需要继续遍历
                    if(!empty($value['where'])) {
                        $temp=$this->build('where_ex',$value['where'],$value['operator']);
                        $sql.='('.$temp.')';
                    } else {
                        // 直接构造
                        // 判断key是否为数组
                        if(is_array($value['key'])) {
                            if(count($value['key'])===1)
                                $value['key']=$value['key'][0];
                            elseif(count($value['key'])===2) {
                                $value['key']=$value['key'][0].'`.`'.$value['key'][1];
                            } else
                                throw new Exception('Where error.',100420);
                        }
                        if($value['operator']==='IN') {
                            $sql.='`'.$value['key'].'` '.$value['operator'].' (';
                            $in_string='';
                            foreach($value['value'] as $value2) {
                                $in_string.=$in_string===''?('?'):(',?');
                                $this->where_value[]=$value2;
                            }
                            $sql.=$in_string.')';
                        } else {
                            $sql.='`'.$value['key'].'` '.$value['operator'].' ?';
                            // 将值存入数组
                            $this->where_value[]=$value['value'];
                        }
                    }
                    $count++;
                    // 如果不为最后一个元素,则添加连接符号
                    if($count<$len)
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
                foreach($this->order as $value) {
                    // 判断value[0]是否为数组
                    if(is_array($value[0])) {
                        if(count($value[0])===1)
                            $value[0]=$value[0][0];
                        elseif(count($value[0])===2) {
                            $value[0]=$value[0][0].'`.`'.$value[0][1];
                        } else
                            throw new Exception('Where error.',100420);
                    }
                    $sql.='`'.$value[0].'` '.$value[1].',';
                }
                return substr($sql,0,-1);
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
                foreach($this->group as $value) {
                    // 判断value是否为数组
                    if(is_array($value)) {
                        if(count($value)===1)
                            $value=$value[0];
                        elseif(count($value)===2) {
                            $value=$value[0].'`.`'.$value[1];
                        } else
                            throw new Exception('Group error.',100450);
                    }
                    $sql.='`'.$value.'`,';
                }
                return substr($sql,0,-1);
            case 'join':
                $sql='';
                // 判断是否为空
                if(empty($this->join))
                    return '';
                foreach($this->join as $value) {
                    $sql.=' '.$this->join_type[$value[2]].' `'.$value[0][0].'` ';
                    // 判断是否有别名
                    if(!empty($value[0][1]))
                        $sql.='`'.$value[0][1].'` ';
                    $sql.='ON ';
                    foreach($value[1] as $value2) {
                        $sql.='`';
                        if(isset($value2[0][1]))
                            $sql.=$value2[0][0].'`.`'.$value2[0][1].'`';
                        else
                            $sql.=$value2[0][0].'`';
                        $sql.=' '.$value2[1].' `';
                        if(isset($value2[2][1]))
                            $sql.=$value2[2][0].'`.`'.$value2[2][1].'`';
                        else
                            $sql.=$value2[2][0].'`';
                        $sql.=' AND ';
                    }
                    $sql=substr($sql,0,-5);
                }
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
        return $this->last_sql??'';
    }

    /**
     * 准备sql语句
     *
     * @access public
     * @param string $sql SQL语句
     * @return PDOStatement
     * @throws Exception
     */
    public function prepare(string $sql): PDOStatement {
        $this->check_connect();
        $this->last_sql=$sql;
        try {
            return $this->db->prepare($sql);
        } catch(PDOException $e) {
            $error=$this->db->errorInfo();
            throw new Exception($error[2],100460,array(
                'sql'=>$sql,
                'info'=>$e->getMessage(),
                'error'=>$error
            ));
        }
    }

    /**
     * 查询数据
     *
     * @access public
     * @param string|array $fields 查询字段(默认为*)
     * @return Generator|array|bool
     * @throws Exception
     */
    public function select(string|array $fields='*'): Generator|array|bool {
        // 如果传入的fields不为“*”则需要过滤字段
        if($fields!=='*')
            $this->field($fields);
        $sql=$this->build('select');
        $stmt=$this->prepare($sql);
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
     * 获取当前查询的表结构语句
     * 
     * @access public
     * @return string
     */
    private function getTableName(): string {
        $table_name='`'.$this->table[0].'`';
        if(!empty($this->table[1])) {
            $table_name.=' `'.$this->table[1].'`';
        }
        return $table_name;
    }

    /**
     * 通过迭代器查询数据
     * 
     * @access public
     * @param object $stmt PDOStatement对象
     * @return Generator
     */
    private function iterator_select(object $stmt): Generator {
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
     * @throws Exception
     */
    public function find(string|array $fields='*'): mixed {
        // 如果传入的fields不为“*”则需要过滤字段
        if($fields!=='*')
            $this->field($fields);
        $sql=$this->build('find');
        $stmt=$this->prepare($sql);
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
     * @throws Exception
     */
    public function count(): int|array {
        $sql=$this->build('count');
        $stmt=$this->prepare($sql);
        // 传入where条件的值
        $i=1;
        foreach($this->where_value as $value) {
            $stmt->bindValue($i,$value);
            $i++;
        }
        if($stmt->execute()) {
            // 判断是否启用了group, 如果启用了group, 则返回数组, 否则返回int
            if(empty($this->group))
                $result=$stmt->fetchColumn();
            else
                $result=$stmt->fetchAll(PDO::FETCH_ASSOC);
            $stmt->closeCursor();
            $this->reset();
            return $result;
        }
        $this->reset();
        return 0;
    }

    /**
     * 插入数据
     *
     * @access public
     * @param array ...$data 数据
     * @return int
     * @throws Exception
     */
    public function insert(array ...$data): int {
        $count=0;
        // 先检查传入数据是否有效
        $data=$this->format_data(...$data);
        foreach($data as $temp) {
            if(!is_array($temp))
                throw new Exception('Insert $data not is array.',100422);
            $sql=$this->build('insert',$temp);
            $stmt=$this->prepare($sql);
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
            // 获取插入了多少条数据
            $count+=$stmt->rowCount();
        }
        // 重置查询状态
        $this->reset();
        return $count;
    }

    /**
     * 更新数据
     *
     * @access public
     * @param array ...$data 数据
     * @return int
     * @throws Exception
     */
    public function update(array ...$data): int {
        $count=0;
        // 先检查传入数据是否有效
        $data=$this->format_data(...$data);
        foreach($data as $temp) {
            if(!is_array($temp))
                throw new Exception('Update $data not is array.',100423);
            // 重置where条件的值
            $this->where_value=array();
            $sql=$this->build('update',$temp);
            $stmt=$this->prepare($sql);
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
            // 获取更新了多少条数据
            $count+=$stmt->rowCount();
        }
        // 重置查询条件
        $this->reset();
        return $count;
    }

    /**
     * 关联查询
     * 
     * @access public
     * @param string|array $table 关联表名
     * @param array $on 关联条件
     * @param string $type 关联类型(left,right,inner,full)
     * @return self
     */
    public function join(string|array $table,array $on,string $type='left'): self {
        // 校验关联类型是否有效
        $type=strtolower($type);
        if(!isset($this->join_type[$type]))
            throw new Exception('Join $type error.',100449);
        // 判断是否为数组
        if(is_string($table)) {
            // 是字符则转为数组
            $table=explode(' ',$table);
            // 判断长度是否为1-2
            if(count($table)>2)
                throw new Exception('Join $table error.',100453);
            // 先清除所有元素的`符号
            $table[0]=trim($table[0],'`');
            if(isset($table[1]))
                $table[1]=trim($table[1],'`');
        }
        // 定义一个临时数组
        $temp=array();
        // 先校验表名是否有效
        $this->check_table($table[0]);
        // 判断是否有别名
        if(isset($table[1]))
            $this->check_table($table[1]);
        // 校验关联条件是否有效
        if(count($on)===0)
            throw new Exception('Join $on error.',100450);
        // 判断第一个元素是否为字符串
        if(is_string($on[0]??false))
            $on=array($on);
        foreach($on as $key=>$value) {
            // 判断key是否为字符串则转为数组
            if(is_string($key))
                $value=array($key,$value);
            // 判断长度是否为2-3
            if(count($value)===2)
                $value=array($value[0],'=',$value[1]);
            elseif(count($value)===3) {
                // 判断第二个元素是否为操作符
                if(!in_array($value[1],$this->operator))
                    throw new Exception('Join $on error.',100451);
            } else
                throw new Exception('Join $on error.',100452);
            $value[0]=$this->field_to_array($value[0]);
            $value[2]=$this->field_to_array($value[2]);
            $temp[]=array($value[0],$value[1],$value[2]);
        }
        $this->join[]=array($table,$temp,$type);
        return $this;
    }

    /**
     * 设置过滤字段
     * 
     * @access public
     * @param array|string $fields 过滤字段
     * @return self
     */
    public function field(array|string $fields): self {
        // 如果是字符串且不为“*”则转为数组
        if(is_string($fields)&&$fields!=='*')
            $fields=explode(',',$fields);
        if(is_array($fields)) {
            foreach($fields as $key=>$value) {
                // 判断key是否为字符串
                if(is_string($key)) {
                    // 将其转为索引数组
                    $value=array($key,$value);
                }
                // 判断value是否为字符串,如果是则转为数组
                if(is_string($value))
                    $value=array($value);
                $temp=$this->field_to_array($value[0]??"");
                // 判断是否有别名
                if(!empty($value[1]))
                    $this->check_key($value[1]);
                switch(count($temp)) {
                    case 1:
                    case 2:
                        $this->filter[]=array($temp,$value[1]??'');
                        break;
                    default:
                        throw new Exception('Filter $fields error.',100410,array(
                            'fields'=>$fields,
                            'value'=>$temp
                        ));
                }
            }
        }
        return $this;
    }

    /**
     * 设置limit限制
     *
     * @access public
     * @param array|int ...$data limit限制
     * @return self
     * @throws Exception
     */
    public function limit(int|array ...$data): self {
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
     * @param array|string ...$data group分组
     * @return self
     * @throws Exception
     */
    public function group(array|string ...$data): self {
        // 先判断传入的数据类型
        foreach($data as $value) {
            if(is_string($value)) {
                $this->group[]=$this->field_to_array($value);
            } else if(is_array($value)) {
                foreach($value as $value2) {
                    if(is_string($value2)) {
                        $this->group[]=$this->field_to_array($value2);
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
     * @param  array|string ...$data order排序
     * @return self
     * @throws Exception
     */
    public function order(array|string ...$data): self {
        // 先判断传入的数据类型
        foreach($data as $value) {
            if(is_string($value)) {
                // 判断是否可以通过空格分割为两个字符串
                $temp=explode(' ',$value);
                if(count($temp)>2)
                    throw new Exception('Order $data length error.',100431);
                // 转为数组
                $value=$temp;
            }
            if(is_array($value)) {
                if(count($value)>2||count($value)<1)
                    throw new Exception('Order $data length error.',100431);
                // 判断第一个字符串是否为字段名
                $value[0]=$this->field_to_array($value[0]);
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
     * @throws Exception
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
     * @throws Exception
     */
    public function where(string|array $where,mixed $data=null,string $operator='='): self {
        $this->check_connect();
        // 先判断$where是否为字符串,如果是则转为数组
        if(is_string($where))
            $where=array(array($where,$data,$operator));
        // 遍历一次数组,如果不全为数组,则抛出异常
        foreach($where as $value) {
            if(!is_array($value))
                throw new Exception('Where error.',100420,array(
                    'message'=>'Unsupported where format.'
                ));
        }
        foreach($where as $key=>$value) {
            // 判断$key是否为字符串,如果是则转为索引数组
            if(is_string($key)) {
                // 判断value是否为数组
                if(is_array($value))
                    $value=array($key,$value[0],$value[1]??$operator);
                else
                    $value=array($key,$value,$operator);
            }
            // 判断第一个参数是否为字符串
            if(!is_string($value[0]))
                throw new Exception('Where error.',100420,array(
                    'where'=>$where,
                    'value'=>$value
                ));
            // 判断操作符是否在允许的范围内
            $temp_operator=$value[2]??$operator;
            if(!in_array($temp_operator,$this->operator))
                throw new Exception('SQL operator error.',100438,array(
                    'operator'=>$temp_operator
                ));
            $this->where_array[]=array(
                'key'=>$this->field_to_array($value[0]),
                'value'=>$value[1]??null,
                'operator'=>$temp_operator
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
     * @throws Exception
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
     * @throws Exception
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
                'key'=>$this->field_to_array($where[0]),
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
     * 将一个字段转换为合法的字段数组
     * 
     * @access private
     * @param string $field 字段名
     * @return array
     * @throws Exception
     */
    private function field_to_array(string $field): array {
        // 将第一个参数通过“.”分割
        $temp=explode('.',$field);
        // 将全部“`”与空格去除
        $temp=array_map(function($value) {
            $value=trim($value);
            return trim($value,'`');
        },$temp);
        // 校验合法性
        if(count($temp)===1)
            $this->check_key($temp[0]);
        else if(count($temp)===2) {
            $this->check_table($temp[0]);
            $this->check_key($temp[1]);
        } else
            throw new Exception('Field error.',100421,array(
                'field'=>$field
            ));
        return $temp;
    }

    /**
     * 检查是否已经连接数据库
     *
     * @access protected
     * @return void
     * @throws Exception
     */
    protected function check_connect(): void {
        // 检查是否已经连接数据库
        if(!$this->is_connect)
            throw new Exception('Database is not connected.',100401);
        // 检查是否已经传递了数据库表名
        if(empty($this->table)&&empty($this->table[0]))
            throw new Exception('Database table name is not set, please use table() to set.',100404);
    }

}