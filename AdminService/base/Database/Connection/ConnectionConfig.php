<?php

namespace base\Database\Connection;

use PDO;
use PDOException;
use base\Database\Exception\ConfigException;
use base\Database\Exception\ConnectionException;
use base\Database\Sql\Dialect\DialectInterface;
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
     * 方言类名(为空时使用 MySQL)
     * @var string|null
     */
    private ?string $dialectClass;

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
     * @param string|null $dialectClass 方言类名(为空时按类型默认, 当前默认 MySQL)
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
        string $tablePrefix='',
        ?string $dialectClass=null
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
        $this->dialectClass=$dialectClass;
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
     * 创建 PDO 连接
     *
     * - DSN 由方言构建, 换方言时 DSN 随之变化
     * - 强制异常模式(ERRMODE_EXCEPTION), 使执行错误以 PDOException 抛出, 保证执行器"失败返回 Result"的错误契约生效
     * - 连接失败包装为 ConnectionException
     *
     * @access public
     * @param DialectInterface|null $dialect 方言(不传则按配置创建)
     * @return PDO
     * @throws ConnectionException
     */
    public function createPdo(?DialectInterface $dialect=null): PDO {
        $dialect=$dialect??$this->createDialect();
        $dsn=$dialect->buildDsn($this->dsnParams());
        $options=$this->options;
        $options[PDO::ATTR_ERRMODE]=PDO::ERRMODE_EXCEPTION;
        try {
            return new PDO($dsn,$this->user,$this->password,$options);
        } catch(PDOException $e) {
            throw new ConnectionException('Database connection failed.',100802,array(
                'dsn'=>$dsn,
                'error'=>$e->getMessage()
            ));
        }
    }

    /**
     * 汇总 DSN 所需连接参数
     *
     * @access private
     * @return array
     */
    private function dsnParams(): array {
        return array(
            'type'=>$this->type,
            'host'=>$this->host,
            'port'=>$this->port,
            'dbname'=>$this->dbname,
            'charset'=>$this->charset,
        );
    }

    /**
     * 创建连接会话
     *
     * - 方言类可通过配置手动绑定, 未指定时使用 MySQL
     *
     * @access public
     * @return PdoConnectionSession
     * @throws ConfigException 方言类未实现 DialectInterface
     */
    public function createSession(): PdoConnectionSession {
        $dialect=$this->createDialect();
        return new PdoConnectionSession($this->createPdo($dialect),$dialect,$this->tablePrefix);
    }

    /**
     * 创建方言
     *
     * - 方言类可通过配置手动绑定, 未指定时使用 MySQL
     *
     * @access public
     * @return DialectInterface
     * @throws ConfigException 方言类未实现 DialectInterface
     */
    public function createDialect(): DialectInterface {
        $dialect=$this->dialectClass!==null?new $this->dialectClass():new MysqlDialect();
        if(!$dialect instanceof DialectInterface)
            throw new ConfigException('Invalid dialect class.',100803,array(
                'class'=>$this->dialectClass
            ));
        return $dialect;
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
