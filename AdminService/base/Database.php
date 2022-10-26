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
    public string $table;

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
            $config['user']??$this->db_config['user'],
            $config['password']??$this->db_config['password']
        );
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
     * @return \PDO
     */
    final public function getDb(): \PDO {
        return $this->db;
    }

    /**
     * 设置数据库表名
     * 
     * @access public
     * @param string $table 数据库表名
     * @return object
     */
    final public function setTable(string $table): object {
        $this->table=Config::get('database.default.prefix','').$table;
        return $this->db_object;
    }

    /**
     * 初始化
     * 
     * @access protected
     * @param array $config 数据库配置信息
     * @return void
     */
    final protected function init(array $config=array()): void {
        $this->config($config);
        // 判断数据库是否受到支持
        $support_type=Config::get('database.support_type',array());
        // 判断数据库类型是否在 $support_type 的 key 中存在
        $support_type_key=array_keys($support_type);
        if(in_array($this->db_type,$support_type_key)) {
            $this->db_class=$support_type[$this->db_type];
            $this->db_object=new $this->db_class();
            $this->link();
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

}

?>