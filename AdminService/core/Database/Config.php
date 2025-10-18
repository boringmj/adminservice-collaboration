<?php

namespace AdminService\Database;

use base\Database\ConfigInterface;
use AdminService\exception\sql\ConfigException;

/**
 * 数据库配置类
 */
class Config implements ConfigInterface {

    /**
     * 默认数据库连接名称
     * @var string
     */
    public const DEFAULT_HOST_NAME='localhost';

    /**
     * 默认数据库端口
     * @var int
     */
    public const DEFAULT_DATABASE_PORT=3306;

    /**
     * 默认数据库字符集
     * @var string
     */
    public const DEFAULT_CHARSET='utf8mb4';

    /**
     * 主机名
     * @var string
     */
    public string $host=self::DEFAULT_HOST_NAME;

    /**
     * 端口
     * @var int
     */
    public int $port=self::DEFAULT_DATABASE_PORT;

    /**
     * 字符集
     * @var string
     */
    public string $charset=self::DEFAULT_CHARSET;

    /**
     * 数据库名
     * @var string
     */
    public string $database='';

    /**
     * 用户名
     * @var string
     */
    public string $username='';

    /**
     * 密码
     * @var string
     */
    public string $password='';

    /**
     * 连接选项
     * @var array<int,mixed>
     */
    public array $options=[];

    /**
     * 数据库类型
     * @var string
     */
    public string $type='';

    /**
     * 从数组加载配置
     * @param array<string,mixed> $config 配置数组
     * @return self
     * @throws ConfigException 配置异常
     */
    public static function fromArray(array $config): self {
        $instance=new self();
        // 遍历数组赋值
        foreach($config as $name => $value) {
            // 检查是否存在
            if(!property_exists($instance,$name)) {
                throw new ConfigException("数据库配置项 {$name} 不存在");
            }
            $instance->$name=$value;
        }
        // 检查配置是否完整
        $instance->checkConfig();
        return $instance;
    }

    /**
     * 检查数据库配置是否完整(密码可为空)
     * @return void
     * @throws ConfigException 配置异常
     */
    public function checkConfig(): void {
        // 检查必需的主机名
        if(empty($this->host)) {
            throw new ConfigException('数据库主机名配置为空');
        }
        // 检查端口范围
        if($this->port<=0 || $this->port>65535) {
            throw new ConfigException('数据库端口号应在1-65535范围内');
        }
        // 检查字符集
        if(empty($this->charset)) {
            throw new ConfigException('数据库字符集配置无效或为空');
        }
        // 检查数据库名
        if(empty($this->database)) {
            throw new ConfigException('数据库名配置无效或为空');
        }
        // 检查用户名
        if(empty($this->username)) {
            throw new ConfigException('数据库用户名配置无效或为空');
        }
        // 检查数据库类型
        if(empty($this->type)) {
            throw new ConfigException('数据库类型配置无效或为空');
        }
    }

    /**
     * 获取DSN
     * @return string
     * @param string|null $dsn_template dsn模板
     *  - 默认通过`self::getDsnTemplateByType($this->type)`获取模板
     *  - 支持的占位符:
     *  - \{type\} 数据库类型
     *  - \{host\} 主机名
     *  - \{port\} 端口
     *  - \{charset\} 字符集
     *  - \{database\} 数据库名
     * @throws ConfigException 配置异常
     */
    public function getDsn(?string $dsn_template=null): string {
        $this->checkConfig();
        $dsn_template??=self::getDsnTemplateByType($this->type);
        $dsn_template=str_replace(
            [
                '{type}',
                '{host}',
                '{port}',
                '{charset}',
                '{database}',
            ],
            [
                $this->type,
                $this->host,
                (string)$this->port,
                $this->charset,
                $this->database,
            ],
            $dsn_template
        );
        return $dsn_template;
    }

    /**
     * 通过数据库类型获取默认DSN模板
     * @param string $type 数据库类型
     * @return string
     */
    public static function getDsnTemplateByType(string $type): string {
        return match($type) {
            'mysql'=>'mysql:host={host};port={port};charset={charset};dbname={database}',
            'pgsql'=>'pgsql:host={host};port={port};dbname={database}',
            'sqlite'=>'sqlite:{database}',
            'sqlsrv'=>'sqlsrv:Server={host},{port};Database={database};CharacterSet={charset}',
            default=>throw new ConfigException(
                "Unsupported database type: {$type}"    
            )
        };
    }

    /**
     * 获取用户名
     * @return string
     */
    public function getUsername(): string {
        return $this->username;
    }

    /**
     * 获取密码
     * @return string
     */
    public function getPassword(): string {
        return $this->password;
    }

    /**
     * 获取连接选项
     * @return array<int,mixed>
     */
    public function getOptions(): array {
        return $this->options;
    }

    /**
     * 序列化为数组
     * @param bool $withPassword 是否包含密码
     * @return array<string,mixed>
     */
    public function toArray(bool $withPassword=false): array {
        $result=[
            'host'=>$this->host,
            'port'=>$this->port,
            'charset'=>$this->charset,
            'database'=>$this->database,
            'username'=>$this->username,
            'options'=>$this->options,
            'type'=>$this->type,
        ];
        if($withPassword) {
            $result['password']=$this->password;
        }
        return $result;
    }

}