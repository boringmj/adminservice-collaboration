<?php

namespace base;

use AdminService\Config;
use AdminService\Exception;

abstract class Database {

    /**
     * 数据库连接对象
     */
    protected $db;

    /**
     * 数据库表名
     */
    public string $table;

    /**
     * 数据库类型
     */
    protected string $db_type;

    /**
     * 支持的数据库类型
     */
    protected array $support_db_type;

    /**
     * 数据库配置信息
     */
    protected array $db_config;

    /**
     * 连接数据库
     * 
     * @access public
     * @param array $config 数据库配置信息
     * @return self
     */
    final public function link(array $config=array()): self {
        $this->db_type=$config['type']??$this->db_config['type'];
        if(in_array($this->db_type,$this->support_db_type)) {
            // 通过PDO连接数据库
            $dsn=$this->db_type
                .':host='.$config['host']??$this->db_config['host']
                .';dbname='.$config['dbname']??$this->db_config['dbname']
                .';port='.$config['port']??$this->db_config['port']
                .';charset='.$config['charset']??$this->db_config['charset'];
            $this->db=new \PDO(
                $dsn,
                $config['user']??$this->db_config['user'],
                $config['password']??$this->db_config['password']
            );
            return $this;
        } else
            throw new Exception('Unsupported database type.',100301,array(
                'type'=>$this->db_type
            ));
    }

    /**
     * 配置数据库信息
     * 
     * @access public
     * @param array $config 数据库配置信息
     * @return self
     */
    final public function config(array $config=array()): self {
        $this->db_config=array(
            'type'=>$config['type']??Config::get('database.default.type','mysql'),
            'host'=>$config['host']??Config::get('database.default.host','localhost'),
            'port'=>$config['port']??Config::get('database.default.port',3306),
            'user'=>$config['user']??Config::get('database.default.user',''),
            'password'=>$config['password']??Config::get('database.default.password',''),
            'dbname'=>$config['dbname']??Config::get('database.default.dbname',''),
            'charset'=>$config['charset']??Config::get('database.default.charset','utf8')
        );
        // $this->db_type=$this->db_config['type'];
        return $this;
    }

    /**
     * 获取数据库连接对象
     * 
     * @access public
     * @return self
     */
    final public function getDb(): self {
        return $this->db;
    }

    /**
     * 设置数据库表名
     * 
     * @access public
     * @param string $table 数据库表名
     * @return self
     */
    final public function setTable(string $table): self {
        $this->table=Config::get('database.default.prefix','').$table;
        return $this;
    }

    /**
     * 初始化
     * 
     * @access public
     * @param array $config 数据库配置信息
     * @return self
     */
    final public function init(array $config=array()): self {
        $this->support_db_type=array('mysql');
        $this->config($config);
        return $this;
    }

}

?>