<?php

namespace AdminService;

use \Exception;

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
     * @throws Exception
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
                // 检查文件内容是否安全
                self::checkFile($config_file);
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

    /**
     * 检查文件内容是否安全
     * 我们无法提供更完善的安全检查，只能提供一个简单的安全检查，只需要简单的方法即可绕过，因此建议管理员自行检查配置文件的安全性
     * 
     * @access private
     * @param string $file
     * @return void
     * @throws Exception
     */
    static private function checkFile(string $file): void {
        // 将文件转为绝对路径
        $file=realpath($file);
        $file_content=file_get_contents($file);
        // 判断是否是一个合法的php文件
        if(!preg_match('/^\s*(\<\?(php|=)?)/',$file_content))
            throw new Exception("Config file is not safe: {$file}, please use the php tag");
        // 去除所有行注释和块注释的内容
        $file_content=preg_replace('/\(\/\/|\#.*$/m','',$file_content);
        $file_content=preg_replace('/\/\*.*\*\//s','',$file_content);
        // 先判断最终返回的结果是否是数组
        if(!preg_match('/return\s+(array\(|\[)/',$file_content))
            throw new Exception("Config file is not safe: {$file}, please return an array");
        // 再判断是否有危险的函数
        $list=array(
            'exec',
            'system',
            'shell_exec',
            'passthru',
            'popen',
            'proc_open',
            'pcntl_exec',
            'eval',
            'assert',
            'include',
            'require',
            'include_once',
            'require_once',
            'import',
            'include_once',
            'require_once'
        );
        $perg_str=implode('|',$list);
        if(preg_match('/\b('.$perg_str.')\b\s*\(/',$file_content,$matches))
            throw new Exception("Config file is not safe: {$file}, please remove the function: {$matches[1]}");
        // 判断是否有危险的关键字
        $list=array(
            'include',
            'require',
            'include_once',
            'require_once'
        );
        $perg_str=implode('|',$list);
        // 禁止引入上级目录和协议地址
        if(preg_match('/\b('.$perg_str.')\b\s*[\'"](\.{2}|.*(\/{2}|\\{2}))/',$file_content,$matches))
            throw new Exception("Config file is not safe: {$file}, please remove the keyword: {$matches[1]}");
    }

}

?>