<?php

namespace AdminService;

final class Config {

    /**
     * 配置信息
     * @var array
     */
    static private array $configs;

    /**
     * 构造方法
     * 
     * @access public
     * @param array $configs
     */
    final public function __construct(array $configs=array()) {
        if(!empty($configs))
            self::set($configs);
        return $this;
    }

    /**
     * 加载配置文件
     * 
     * @access public
     * @return void
     */
    final static public function load(): void {
        $temp=array();
        // 读取配置文件目录中所有“.php”文件
        $config_dir=__DIR__.'/../config';
        foreach(glob($config_dir.'/*.php') as $config_file) {
            // 获取到配置文件的名称
            $config_name=basename($config_file,'.php');
            // 使用正则表达式匹配文件名是否符合规范
            if(preg_match('/^[a-zA-Z0-9_]+$/',$config_name)) {
                // 将配置文件的内容写入到配置文件中
                $temp[$config_name]=include $config_file;
            }
        }
        // 将 .env 文件中的配置信息写入到配置文件中
        $env_file=__DIR__.'/../../.env';
        if(file_exists($env_file)) {
            $env_file=file_get_contents($env_file);
            $env_file=explode("\n",$env_file);
            foreach($env_file as $env) {
                // 清除空格
                $env=trim($env);
                // 判断是否是注释或者为空
                if(substr($env,0,1)==='#'||$env==='')
                    continue;
                $env=explode('=',$env);
                $name=strtolower($env[0])??0;
                $value=$env[1]??null;
                // 将name转为数组并赋值
                $name=explode('.',$name);
                $config=&$temp;
                foreach($name as $n) {
                    if(!isset($config[$n]))
                        $config[$n]=array();
                    $config=&$config[$n];
                }
                $config=$value;
            }
        }
        self::set($temp);
    }

    /**
     * 设置配置
     * 
     * @access public
     * @param array $configs
     * @return void
     */
    final static public function set(array $configs): void {
        self::$configs=$configs;
    }

    /**
     * 获取配置
     * 
     * @access public
     * @param string $key 配置键
     * @param mixed $default 默认值
     * @return mixed
     */
    final static public function get(string $key,mixed $default=null): mixed {
        $keys=explode(".",$key);
        $configs=self::$configs;
        foreach ($keys as $key) {
            if (isset($configs[$key]))
                $configs=$configs[$key];
            else
                return $default;
        }
        return $configs;
    }

}

?>