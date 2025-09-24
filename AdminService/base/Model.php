<?php

namespace base;

use \Generator;
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
 * @template T of static
 */
abstract class Model {

    /**
     * 数据表名(自动添加数据表前缀,优先级高于 $table_name)
     * @var string
     */
    public string $table='';

    /**
     * 数据表名(默认不启用自动添加数据表前缀)
     * @var string
     */
    public string $table_name='';

    /**
     * 查询结果集
     * @var array
     */
    protected array $result=[];

    /**
     * 是否返回游标集合类
     * @var bool
     */
    protected bool $cursor_collection=false;

    /**
     * 上一次查询结果是否为空
     * @var bool
     */
    protected bool $last_is_empty=true;

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
     * @param bool $last_is_empty 上一次查询结果是否为空
     * @return void
     * @throws Exception
     */
    public function __construct(array $data=[],$last_is_empty=true) {
        $this->result=$data;
        $this->db=App::new(Database::class);
        $this->autoGetTable();
        $this->last_is_empty=$last_is_empty;
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
        if(!array_key_exists($name,$this->result))
            throw new Exception('Property "'.$name.'" not found.');
        // 设置结果集中的属性值
        $this->result[$name]=$value;
    }

    /**
     * 获取结果集中的属性是否存在
     * 
     * @access public
     * @param string $name 属性名
     */
    public function __isset(string $name): bool {
        return isset($this->result[$name]);
    }

    /**
     * 检查属性是否存在
     * 
     * @access public
     * @param string $name 属性名
     */
    public function has(string $name): bool {
        return array_key_exists($name,$this->result);
    }

    /**
     * 检查属性是否为空
     * 
     * @access public
     * @param string $name 属性名
     */
    public function emptyValue(string $name): bool {
        return empty($this->result[$name]);
    }

    /**
     * 判断上一次Sql语句执行结果是否为空
     * 
     * @access public
     * @return bool
     */
    public function isEmpty(): bool {
        return $this->last_is_empty;
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
     * @param bool $last_is_empty 上一次查询结果是否为空
     * @return T
     * @throws Exception
     */
    static public function new(array $data=[],bool $last_is_empty=true): static {
        return new static($data,$last_is_empty);
    }

    /**
     * 创建新的实例并切换数据表
     * 
     * @access public
     * @param string|array|null $table 数据库表名
     */
    static public function tableNew(null|array|string $table=null): static {
        $instance=self::new();
        $instance->table($table);
        return $instance;
    }

    /**
     * 根据上一次查询的字段构造一个空的结果集
     * 
     * @access protected
     * @param string|array $fields 使用的查询字段(默认为*)
     * @return array
     */
    protected function buildEmptyResult(string|array $fields='*'): array {
        if(is_string($fields)&&$fields!=='*')
            return [$fields=>null];
        $last_filter=$this->db->getLastFields();
        $result=[];
        foreach($last_filter as $value) {
            // 优先使用别名
            if(!empty($value[1])) {
                $result[$value[1]]=null;
                continue;
            }
            // 如果别名不存在则使用字段名称
            if(is_array($value[0])) {
                // 判断是否有表名
                if(count($value[0])===2)
                    $result[$value[0][1]]=null;
                else
                    $result[$value[0][0]]=null;
            }
            else
                $result[$value[0]]=null;
        }
        return $result;
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
        $result=$this->db->find($fields);
        $last_is_empty=$this->db->isEmpty();
        if(is_string($fields)&&$fields!=='*')
            return static::new([$fields=>$result],$last_is_empty);
        if(empty($result))
            return static::new($this->buildEmptyResult($fields),$last_is_empty);
        return static::new($result,$last_is_empty);
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
     * @return CursorCollection<T>|Collection<T>|Generator<int,T>
     * @throws Exception
     */
    public function select(
        string|array $fields='*'
    ): CursorCollection|Collection|Generator {
        $result=$this->db->select($fields);
        // 如果是迭代器则直接返回
        if($result instanceof Generator) {
            if($this->cursor_collection) {
                $this->cursor_collection=false;
                return new CursorCollection(static::class,$result);
            }
            else return $this->yieldResult($result);
        }
        $this->cursor_collection=false;
        return new Collection(static::class,$result);
    }

    /**
     * 迭代器处理结果
     *  
     * @access public
     * @param Generator $result 数据
     * @return Generator
     */
    protected function yieldResult(Generator $result): Generator {
        $last_is_empty=$this->db->isEmpty();
        foreach($result as $data) {
            yield static::new($data,$last_is_empty);
        }
    }

    /**
     * 保存数据(依赖主键,如果不提供主键则视为插入)
     * 
     * @access public
     * @param array $data 数据
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
     * @return static
     * @throws Exception
     */
    public function where(string|array $where,mixed $data=null,string $operator='='): static {
        $this->db->where($where,$data,$operator);
        return $this;
    }

     /**
     * 高级查询
     * 
     * @access public
     * @param array ...$data 高级查询条件
     * @return static
     */
    public function whereEx(array ...$data): static {
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
     * 返回当前过滤字段
     * 
     * @access public
     * @return array
     */
    public function getFields(): array {
        return $this->db->getFields();
    }

    /**
     * 返回上一次的过滤字段
     * @access public
     * @return array
     */
    public function getLastFields(): array {
        return $this->db->getLastFields();
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
     * @return static
     */
    public function limit(array|int ...$data): static {
        $this->db->limit(...$data);
        return $this;
    }

    /**
     * 设置order排序(仅对 select 和 find 生效)
     * 
     * @access public
     * @param array|string ...$data order排序
     * @return static
     */
    public function order(array|string ...$data): static {
        $this->db->order(...$data);
        return $this;
    }

    /**
     * 设置group分组(仅对 select, find 和 count 生效)
     * 
     * @access public
     * @param array|string ...$data group分组
     * @return static
     */
    public function group(array|string ...$data): static {
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
     * 设置下一次返回数据为迭代器而非对象集合(仅对 select 生效)
     * 
     * @access public
     * @param bool $cursor_collection 是否返回游标集合类(游标集合类是一次性消费类)
     * @return static
     */
    public function iterator(bool $cursor_collection=false): static {
        $this->cursor_collection=$cursor_collection;
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
     * 重置查询状态
     * 
     * @access protected
     * @return static
     */
    public function reset(): static {
        $this->db->reset();
        return $this;
    }

    /**
     * 为当前语句设置显式行锁
     * 
     * @access public
     * @param string $type 锁类型(shared,update且默认为update,不区分大小写,其他值无效)
     * @return static
     */
    public function lock(string $type='update'): static {
        $this->db->lock($type);
        return $this;
    }

    /**
     * 设置当前查询主表别名
     * 
     * @access public
     * @param string $alias 别名
     * @return static
     */
    public function alias(string $alias): static {
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
     * @return static
     */
    public function join(string|array $table,array $on,string $type='left'): static {
        $this->db->join($table,$on,$type);
        return $this;
    }

    /**
     * 设置过滤字段
     * 
     * @access public
     * @param array|string $fields 过滤字段
     * @return static
     */
    public function field(array|string $fields): static {
        $this->db->field($fields);
        return $this;
    }

}