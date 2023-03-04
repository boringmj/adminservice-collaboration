<?php

namespace base;

use AdminService\Config;
use AdminService\Exception;

abstract class Database {

    /**
     * 数据库连接对象
     * @var \PDO
     */
    protected \PDO $db;

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
     * @var object
     */
    protected object $db_object;

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
        $this->db=new \PDO(
            $dsn,
            $this->db_config['user'],
            $this->db_config['password']
        );
        // 这是为了防止 PDO::FETCH_ASSOC 返回的数据类型为 string
        $this->db->setAttribute(\PDO::ATTR_STRINGIFY_FETCHES, false);
        $this->db->setAttribute(\PDO::ATTR_EMULATE_PREPARES, false);
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
     * @access protected
     * @return \PDO
     */
    final protected function getDb(): \PDO {
        return $this->db;
    }

    /**
     * 设置数据库表名
     * 
     * @access protected
     * @param string $table 数据库表名
     * @param bool $prefix 是否自动添加表前缀(默认添加)
     * @return self
     */
    final protected function table(string $table=null,bool $prefix=true): self {
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
     * @return void
     */
    final protected function init(array $config=array()): void {
        $this->is_table_name=false;
        $this->config($config);
        // 判断PDO是否支持该数据库类型
        if(!in_array($this->db_type,\PDO::getAvailableDrivers()))
            throw new Exception('PDO does not support this database type.',100302,array(
                'type'=>$this->db_type
            ));
        // 判断数据库是否受到支持
        $support_type=Config::get('database.support_type',array());
        // 判断数据库类型是否在 $support_type 的 key 中存在
        $support_type_key=array_keys($support_type);
        if(in_array($this->db_type,$support_type_key)) {
            $this->db_class=$support_type[$this->db_type];
            $this->link();
            $this->db_object=new $this->db_class($this->db,$this->table_name??null);
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
     */
    public function __construct(array $config=array()) {
        $this->init($config);
    }

    /**
     * 开启事务
     * 
     * @access protected
     * @return void
     */
    protected function beginTransaction(): void {
        $this->db_object->beginTransaction();
    }

    /**
     * 提交事务
     * 
     * @access protected
     * @return void
     */
    protected function commit(): void {
        $this->db_object->commit();
    }

    /**
     * 回滚事务
     * 
     * @access protected
     * @return void
     */
    protected function rollBack(): void {
        $this->db_object->rollBack();
    }

    /**
     * 查询数据
     * 
     * @access protected
     * @param string|array $fields 查询字段(默认为*)
     * @return mixed
     */
    protected function select(string|array $fields='*'): mixed {
        $this->autoTable();
        return $this->db_object->select($fields);
    }

    /**
     * 查询一条数据
     * 
     * @access protected
     * @param string|array $fields 查询字段(默认为*)
     * @return mixed
     */
    protected function find(string|array $fields='*'): mixed {
        $this->autoTable();
        return $this->db_object->find($fields);
    }

    /**
     * 根据条件查询数据
     * 
     * @access protected
     * @param string|array $where 字段名称或者数据数组
     * @param mixed $data 查询数据
     * @param string $operator 操作符
     * @return self
     */
    protected function where(string|array $where,mixed $data=null,?string $operator='='): self {
        $this->db_object->where($where,$data,$operator);
        return $this;
    }

    /**
     * 插入数据
     * 
     * @access protected
     * @param array ...$data 数据
     * @return bool
     */
    protected function insert(...$data): bool {
        $this->autoTable();
        return $this->db_object->insert(...$data);
    }

    /**
     * 更新数据
     * 
     * @access protected
     * @param array ...$data 数据
     * @return bool
     */
    protected function update(...$data): bool {
        $this->autoTable();
        return $this->db_object->update(...$data);
    }

    /**
     * 删除数据
     * 
     * @access protected
     * @param int|string|array|null $data 主键或者组件组
     * @return bool
     */
    protected function delete(int|string|array|null $data=null): bool {
        $this->autoTable();
        return $this->db_object->delete($data);
    }

    /**
     * 设置下一次返回数据为迭代器(仅对 select 生效)
     * 
     * @access public
     * @return self
     */
    public function iterator(): self {
        $this->db_object->iterator();
        return $this;
    }

    /**
     * 重置查询状态
     * 
     * @access public
     * @return self
     */
    public function reset(): self {
        $this->db_object->reset();
        return $this;
    }


}

?>