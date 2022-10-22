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