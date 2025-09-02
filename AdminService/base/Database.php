<?php

namespace base;

use AdminService\Config;
use AdminService\Exception;
use \PDO;

class Database {

    /**
     * 数据库连接对象
     * @var PDO
     */
    static protected PDO $db;

    /**
     * 数据库表名
     * @var string
     */
    public string $table_name;

    /**
     * 数据库类型
     * @var string
     */
    protected string $db_type;

    /**
     * 数据库操作使用的类名
     * @var string
     */
    protected string $db_class;

    /**
     * 数据库操作使用的类对象
     * @var SqlDrive
     */
    protected SqlDrive $db_object;

    /**
     * 数据库配置信息
     * @var array
     */
    protected array $db_config;

    /**
     * 是否已经传递了表名
     * @var bool
     */
    protected bool $is_table_name;

    /**
     * 连接数据库
     * 
     * @access protected
     * @return void
     */
    final protected function link(): void {
        // 通过PDO连接数据库
        $dsn=$this->db_type
            .':host='.$this->db_config['host']
            .';dbname='.$this->db_config['dbname']
            .';port='.$this->db_config['port']
            .';charset='.$this->db_config['charset'];
        $this::$db=new PDO(
            $dsn,
            $this->db_config['user'],
            $this->db_config['password']
        );
        // 这是为了防止 PDO::FETCH_ASSOC 返回的数据类型为 string
        $this::$db->setAttribute(PDO::ATTR_STRINGIFY_FETCHES,false);
        $this::$db->setAttribute(PDO::ATTR_EMULATE_PREPARES,false);
    }

    /**
     * 配置数据库信息
     * 
     * @access protected
     * @param array $config 数据库配置信息
     * @return void
     */
    final protected function config(array $config=array()): void {
        $this->db_config=array(
            'type'=>$config['type']??Config::get('database.default.type','mysql'),
            'host'=>$config['host']??Config::get('database.default.host','localhost'),
            'port'=>$config['port']??Config::get('database.default.port',3306),
            'user'=>$config['user']??Config::get('database.default.user',''),
            'password'=>$config['password']??Config::get('database.default.password',''),
            'dbname'=>$config['dbname']??Config::get('database.default.dbname',''),
            'charset'=>$config['charset']??Config::get('database.default.charset','utf8')
        );
        $this->db_type=$this->db_config['type'];
    }

    /**
     * 获取数据库连接对象
     * 
     * @access public
     * @return PDO
     */
    final public function getDb(): PDO {
        return $this::$db;
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
        if($table===null)
            return $this;
        $table_name=($prefix?Config::get('database.default.prefix',''):'').$table;
        $this->db_object->table($table_name);
        $this->is_table_name=true;
        return $this;
    }

    /**
     * 自动判断是否需要传递表名(如果没有传递则传递)
     *
     * @access private
     * @return void
     * @throws Exception
     */
    private function autoTable(): void {
        if(!$this->is_table_name) {
            // 原则上默认是不启用自动添加表前缀的
            # $table_name=isset($this->table_name)?(Config::get('database.default.prefix','').$this->table_name):null;
            $table_name=$this->table_name??null;
            $this->db_object->table($table_name);
        }
        $this->is_table_name=false;
    }

    /**
     * 初始化
     *
     * @access protected
     * @param array $config 数据库配置信息
     * @param bool force 是否强制重新连接(默认不强制)
     * @return void
     * @throws Exception
     */
    final protected function init(array $config=array(),bool $force=false): void {
        $this->is_table_name=false;
        $this->config($config);
        // 判断PDO是否支持该数据库类型
        if(!in_array($this->db_type,PDO::getAvailableDrivers()))
            throw new Exception('PDO does not support this database type.',100302,array(
                'type'=>$this->db_type
            ));
        // 判断数据库是否受到支持
        $support_type=Config::get('database.support_type',array());
        // 判断数据库类型是否在 $support_type 的 key 中存在
        $support_type_key=array_keys($support_type);
        if(in_array($this->db_type,$support_type_key)) {
            $this->db_class=$support_type[$this->db_type];
            if($force || !isset($this::$db) || !$this::$db instanceof PDO)
                $this->link();
            $this->db_object=new $this->db_class($this::$db,$this->table_name??null);
        } else
            throw new Exception('Unsupported database type.',100301,array(
                'type'=>$this->db_type
            ));
    }

    /**
     * 构造函数(会自动初始化并连接数据库)
     *
     * @access public
     * @param array $config 数据库配置信息
     * @throws Exception
     */
    public function __construct(array $config=array()) {
        $this->init($config);
    }

    /**
     * 开启事务
     *
     * @access public
     * @return void
     * @throws Exception
     */
    public function beginTransaction(): void {
        $this->db_object->beginTransaction();
    }

    /**
     * 提交事务
     *
     * @access public
     * @return void
     * @throws Exception
     */
    public function commit(): void {
        $this->db_object->commit();
    }

    /**
     * 回滚事务
     *
     * @access public
     * @return void
     * @throws Exception
     */
    public function rollBack(): void {
        $this->db_object->rollBack();
    }

    /**
     * 查询数据
     *
     * @access public
     * @param string|array $fields 查询字段(默认为*)
     * @return mixed
     * @throws Exception
     */
    public function select(string|array $fields='*'): mixed {
        $this->autoTable();
        return $this->db_object->select($fields);
    }

    /**
     * 查询一条数据
     *
     * @access public
     * @param string|array $fields 查询字段(默认为*)
     * @return mixed
     * @throws Exception
     */
    public function find(string|array $fields='*'): mixed {
        $this->autoTable();
        return $this->db_object->find($fields);
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
        $this->db_object->where($where,$data,$operator);
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
        $this->db_object->whereEx(...$data);
        return $this;
    }

    /**
     * 获取上一次执行的SQL语句
     * 
     * @access public
     * @return string
     */
    public function getLastSql(): string {
        return $this->db_object->getLastSql();
    }

    /**
     * 返回当前过滤字段
     * 
     * @access public
     * @return array
     */
    public function getFields(): array {
        return $this->db_object->getFields();
    }

    /**
     * 返回上一次的过滤字段
     * @access public
     * @return array
     */
    public function getLastFields(): array {
        return $this->db_object->getLastFields();
    }

    /**
     * 判断上一次Sql语句执行结果是否为空
     * 
     * @access public
     * @return bool
     */
    public function isEmpty(): bool {
        return $this->db_object->isEmpty();
    }

    /**
     * 获取最后插入的ID
     * 
     * @access public
     * @param string|null $name 序列名称
     * @return string|false
     */
    public function getLastInsertId(?string $name=null): string|false {
        return $this->db_object->getLastInsertId($name);
    }


    /**
     * 获取受影响的行数,
     * 更新和插入可能分多次进行,所以可能与实际受影响行数不符,
     * 建议直接使用相关方法返回值以确保准确性
     * 
     * @access public
     * @return int
     */
    public function rowCount(): int {
        return $this->db_object->rowCount();
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
        $this->autoTable();
        return $this->db_object->insert(...$data);
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
        $this->autoTable();
        return $this->db_object->update(...$data);
    }

    /**
     * 设置limit限制(仅对 select 生效)
     * 
     * @access public
     * @param array|int ...$data limit限制
     * @return static
     */
    public function limit(array|int ...$data): static {
        $this->db_object->limit(...$data);
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
        $this->db_object->order(...$data);
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
        $this->db_object->group(...$data);
        return $this;
    }

    /**
     * 统计当前查询条件下的数据总数
     *
     * @access public
     * @return int|array
     * @throws Exception
     */
    public function count(): int|array {
        $this->autoTable();
        return $this->db_object->count();
    }

    /**
     * 设置当前查询主表别名
     * 
     * @access public
     * @param string $alias 别名
     * @return static
     */
    public function alias(string $alias): static {
        $this->db_object->alias($alias);
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
        $this->db_object->join($table,$on,$type);
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
        $this->db_object->field($fields);
        return $this;
    }

    /**
     * 为当前语句设置显式行锁
     *
     * @access public
     * @param string $type 锁类型(shared,update且默认为update,不区分大小写,其他值无效)
     * @return static
     * @throws Exception
     */
    public function lock(string $type='update'): static {
        $this->db_object->lock($type);
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
        $this->autoTable();
        return $this->db_object->delete($data);
    }

    /**
     * 设置下一次返回数据为迭代器(仅对 select 生效)
     * 
     * @access public
     * @return static
     */
    public function iterator(): static {
        $this->db_object->iterator();
        return $this;
    }

    /**
     * 重置查询状态
     * 
     * @access public
     * @return static
     */
    public function reset(): static {
        $this->db_object->reset();
        return $this;
    }

}