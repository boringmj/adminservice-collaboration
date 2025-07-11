<?php

namespace base;

use AdminService\App;
use AdminService\Config;
use AdminService\Exception;

/**
 * 模型基类
 * 
 * @access public
 * @abstract
 * @package base
 * @version 1.0.0
 */
abstract class Model {

    /**
     * 数据表名(自动添加数据表前缀,优先级高于 $table_name)
     * 
     * @var string
     */
    public string $table='';

    /**
     * 数据表名(默认不启用自动添加数据表前缀)
     * 
     * @var string
     */
    public string $table_name='';

    /**
     * 查询结果集
     * 
     * @var array
     */
    protected array $result=[];

    /**
     * Database对象
     * 
     * @var Database
     */
    protected Database $db;

    /**
     * 构造函数
     * 
     * @access public
     * @param array $data 结果集
     * @return void
     * @throws Exception
     */
    public function __construct(array $data=[]) {
        $this->result=$data;
        $this->db=App::new(Database::class);
        $this->autoGetTable();
    }

    /**
     * 自动判断数据表名
     *
     * @access private
     * @return void
     * @throws Exception
     */
    private function autoGetTable(): void {
        $prefix=Config::get('database.default.prefix');
        if(empty($this->table) && empty($this->table_name))
            $this->table_name=$prefix.$this->classToTable(get_class($this));
        else if(!empty($this->table))
            $this->table_name=$prefix.$this->table;
        $this->table($this->table_name,false);
    }

    /**
     * 将类名转为下划线分隔的小写表名
     * 
     * @author DeepSeek
     * @access protected
     * @param string $class_name 类名
     * @return string
     */
    protected function classToTable(string $class_name): string {
        // 剥离命名空间，获取基本类名
        $base_class=basename(str_replace('\\','/',$class_name));
        // 处理三种转换场景：
        // 1. 小写字母后的大写字母（驼峰边界）
        // 2. 大写字母后的大写字母+小写字母（首字母缩写边界）
        // 3. 字母与数字边界
        $converted=preg_replace([
            '/(?<=[a-z])(?=[A-Z])/',         // userInfo → user_Info
            '/(?<=[A-Z])(?=[A-Z][a-z])/',    // XMLParser → XML_Parser
            '/(?<=[a-zA-Z])(?=\d)|(?<=\d)(?=[a-zA-Z])/'  // user2FA → user_2FA
        ],'_',$base_class);
        return strtolower($converted);
    }

    /**
     * 获取结果集中的属性是否存在
     *
     * @access public
     * @param string $name 属性名
     * @return mixed
     * @throws Exception
     */
    public function __get(string $name): mixed {
        // 检查结果集中是否存在该属性
        if(!array_key_exists($name,$this->result))
            throw new Exception('Property "'.$name.'" not found.');
        // 返回结果集中的属性值
        return $this->result[$name];
    }

    /**
     * 设置结果集中的属性
     * 
     * @access public
     * @param string $name 属性名
     * @param mixed $value 属性值
     * @return void
     * @throws Exception
     */
    public function __set(string $name,mixed $value): void {
        // 检查结果集中是否存在该属性
        if (!array_key_exists($name,$this->result))
            throw new Exception('Property "'.$name.'" not found.');
        // 设置结果集中的属性值
        $this->result[$name]=$value;
    }

    /**
     * 以数组的形式获取结果集
     * 
     * @access public
     * @return array
     */
    public function toArray(): array {
        return $this->result;
    }

    /**
     * 创建新的数据集
     * 
     * @access public
     * @param array $data 数据
     * @return static
     * @throws Exception
     */
    public function new(array $data=[]): static {
        return new static($data);
    }

    /**
     * 查询一条数据
     *  
     * @access public
     * @param string|array $fields 查询字段(默认为*)
     * @return static
     * @throws Exception
     */
    public function find(string|array $fields='*'): static {
        return $this->new($this->db->find($fields));
    }

    /**
     * 查询一条数据
     *  
     * @access public
     * @param string|array $fields 查询字段(默认为*)
     * @return static
     * @throws Exception
     */
    public function get(string|array $fields='*'): static {
        return $this->find($fields);
    }
    
    /**
     * 查询数据
     *  
     * @access public
     * @param string|array $fields 查询字段(默认为*)
     * @return Collection
     * @throws Exception
     */
    public function select(string|array $fields='*'): Collection {
        return new Collection($this,$this->db->select($fields));
    }

    /**
     * 保存数据(依赖主键,如果不提供主键则视为插入)
     * 
     * @access public
     * @param array $data 数据(提供视为插入,不提供且不提供主键视为更新)
     * @param bool $throw 是否抛出异常
     * @return bool
     * @throws Exception
     */
    public function save(array $data=[],bool $throw=true): bool {
        try {
            if(empty($data)) {
                if(empty($this->result))
                    throw new Exception('No data to update.');
                if(!array_key_exists('id',$this->result)) {
                    // 如果没有主键则视为插入
                    if($this->insert($this->result))
                        return true;
                    throw new Exception('No id to update.');
                }
                if(!$this->update($this->result))
                    throw new Exception('Update failed.');
                return true;
            }
            if(!$this->insert($data))
                throw new Exception('Insert failed.');
            return true;
        } catch(Exception $e) {
            if($throw)
                throw $e;
            return false;
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
     * @throws Exception
     */
    public function where(string|array $where,mixed $data=null,string $operator='='): self {
        $this->db->where($where,$data,$operator);
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
        $this->db->whereEx(...$data);
        return $this;
    }

    /**
     * 设置数据库表名
     *
     * @access public
     * @param string|array|null $table 数据库表名
     * @param bool $prefix 是否自动添加表前缀(默认添加)
     * @return static
     * @throws Exception
     */
    final public function table(null|array|string $table=null,bool $prefix=true): static {
        $this->db->table($table,$prefix);
        return $this;
    }

    /**
     * 获取上一次执行的SQL语句
     * 
     * @access public
     * @return string
     */
    public function getLastSql(): string {
        return $this->db->getLastSql();
    }

    /**
     * 插入数据
     * 
     * @access public
     * @param array ...$data 数据
     * @return int
     */
    public function insert(array ...$data): int {
        return $this->db->insert(...$data);
    }

    /**
     * 更新数据
     * 
     * @access public
     * @param array ...$data 数据
     * @return int
     */
    public function update(array ...$data): int {
        return $this->db->update(...$data);
    }

    /**
     * 设置limit限制(仅对 select 生效)
     * 
     * @access public
     * @param array|int ...$data limit限制
     * @return self
     */
    public function limit(array|int ...$data): self {
        $this->db->limit(...$data);
        return $this;
    }

    /**
     * 设置order排序(仅对 select 和 find 生效)
     * 
     * @access public
     * @param array|string ...$data order排序
     * @return self
     */
    public function order(array|string ...$data): self {
        $this->db->order(...$data);
        return $this;
    }

    /**
     * 设置group分组(仅对 select, find 和 count 生效)
     * 
     * @access public
     * @param array|string ...$data group分组
     * @return self
     */
    public function group(array|string ...$data): self {
        $this->db->group(...$data);
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
        return $this->db->delete($data);
    }

    /**
     * 开启事务
     * 
     * @access public
     * @return void
     */
    public function beginTransaction(): void {
        $this->db->beginTransaction();
    }

    /**
     * 提交事务
     * 
     * @access public
     * @return void
     */
    public function commit(): void {
        $this->db->commit();
    }

    /**
     * 回滚事务
     * 
     * @access public
     * @return void
     */
    public function rollBack(): void {
        $this->db->rollBack();
    }

    /**
     * 设置下一次返回数据为迭代器(仅对 select 生效)
     * 
     * @access public
     * @return self
     */
    public function iterator(): self {
        $this->db->iterator();
        return $this;
    }

    /**
     * 统计当前查询条件下的数据总数
     * 
     * @access public
     * @return int|array
     */
    public function count(): int|array {
        return $this->db->count();
    }

    /**
     * 自动去重复(仅对 select 和 count 生效)
     * 
     * @access public
     * @return self
     */
    public function distinct(): self {
        $this->db->distinct();
        return $this;
    }

    /**
     * 重置查询状态
     * 
     * @access protected
     * @return self
     */
    public function reset(): self {
        $this->db->reset();
        return $this;
    }

    /**
     * 为当前语句设置显式行锁
     * 
     * @access public
     * @param string $type 锁类型(shared,update且默认为update,不区分大小写,其他值无效)
     * @return self
     */
    public function lock(string $type='update'): self {
        $this->db->lock($type);
        return $this;
    }

    /**
     * 设置当前查询主表别名
     * 
     * @access public
     * @param string $alias 别名
     * @return self
     */
    public function alias(string $alias): self {
        $this->db->alias($alias);
        return $this;
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
        $this->db->join($table,$on,$type);
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
        $this->db->field($fields);
        return $this;
    }

}