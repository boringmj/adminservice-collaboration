<?php

namespace base\Database\Connection;

use PDO;
use base\Database\Sql\Dialect\MysqlDialect;

/**
 * 连接配置
 *
 * - 不可变值对象, 描述数据库连接所需参数
 * - 负责构建 DSN 与 PDO 连接, 并可按配置创建连接会话
 */
final class ConnectionConfig {

    /**
     * 数据库类型
     * @var string
     */
    private string $type;

    /**
     * 主机
     * @var string
     */
    private string $host;

    /**
     * 端口
     * @var int
     */
    private int $port;

    /**
     * 用户名
     * @var string
     */
    private string $user;

    /**
     * 密码
     * @var string
     */
    private string $password;

    /**
     * 数据库名
     * @var string
     */
    private string $dbname;

    /**
     * 字符集
     * @var string
     */
    private string $charset;

    /**
     * PDO 连接选项
     * @var array
     */
    private array $options;

    /**
     * 表前缀
     * @var string
     */
    private string $tablePrefix;

    /**
     * 构造方法
     *
     * @access public
     * @param string $type 数据库类型(默认 mysql)
     * @param string $host 主机
     * @param int $port 端口
     * @param string $user 用户名
     * @param string $password 密码
     * @param string $dbname 数据库名
     * @param string $charset 字符集
     * @param array $options PDO 连接选项
     * @param string $tablePrefix 表前缀
     */
    public function __construct(
        string $type='mysql',
        string $host='localhost',
        int $port=3306,
        string $user='',
        string $password='',
        string $dbname='',
        string $charset='utf8mb4',
        array $options=array(),
        string $tablePrefix=''
    ) {
        $this->type=$type;
        $this->host=$host;
        $this->port=$port;
        $this->user=$user;
        $this->password=$password;
        $this->dbname=$dbname;
        $this->charset=$charset;
        $this->options=$options;
        $this->tablePrefix=$tablePrefix;
    }

    /**
     * 获取数据库类型
     *
     * @access public
     * @return string
     */
    public function getType(): string {
        return $this->type;
    }

    /**
     * 获取表前缀
     *
     * @access public
     * @return string
     */
    public function getTablePrefix(): string {
        return $this->tablePrefix;
    }

    /**
     * 构建 DSN
     *
     * @access public
     * @return string
     */
    public function buildDsn(): string {
        return $this->type
            .':host='.$this->host
            .';dbname='.$this->dbname
            .';port='.$this->port
            .';charset='.$this->charset;
    }

    /**
     * 创建 PDO 连接
     *
     * @access public
     * @return PDO
     */
    public function createPdo(): PDO {
        return new PDO($this->buildDsn(),$this->user,$this->password,$this->options);
    }

    /**
     * 创建连接会话
     *
     * - 目前仅支持 mysql 方言
     *
     * @access public
     * @return PdoConnectionSession
     */
    public function createSession(): PdoConnectionSession {
        return new PdoConnectionSession($this->createPdo(),new MysqlDialect(),$this->tablePrefix);
    }

    /**
     * 创建会话工厂(供连接池/管理器使用)
     *
     * @access public
     * @return callable
     */
    public function sessionFactory(): callable {
        $config=$this;
        return function() use ($config) {
            return $config->createSession();
        };
    }

}
