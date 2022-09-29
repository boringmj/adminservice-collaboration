<?php

namespace AdminService;

final class Config {

    /**
     * 配置信息
     */
    static private $configs;

    /**
     * 构造方法
     * 
     * @access public
     * @param array $configs
     * @return Config
     */
    final public function __construct(array $configs) {
        self::$configs=$configs;
        return $this;
    }

    /**
     * 获取配置
     * 
     * @access public
     * @param string $key
     * @return mixed
     */
    final static public function get(string $key) {
        $keys=explode(".", $key);
        $configs=self::$configs;
        foreach ($keys as $key) {
            if (isset($configs[$key])) {
                $configs=$configs[$key];
            } else {
                return null;
            }
        }
        return $configs;
    }
}

?>