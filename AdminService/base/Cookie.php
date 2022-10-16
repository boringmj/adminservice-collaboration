<?php

namespace base;

abstract class Cookie {
    
    /**
     * 通过数组的方式设置cookie
     * 
     * @access public
     * @param array $data 数据
     * @return void
     */
    abstract static public function setByArray(array $data): void;

    /**
    * 设置Cookie
    * 
    * @access public
    * @param string $name 名称
    * @param mixed $value 值
    * @param int $expire 过期时间
    * @param string $path 路径
    * @param string $domain 域名
    * @param bool $secure 是否仅https
    * @param bool $httponly 是否仅http
    * @return void
    */
    abstract static public function set(
        string $name,mixed $value,
        ?int $expire=null,?string $path=null,?string $domain=null,
        ?bool $secure=null,?bool $httponly=null
    ): void;

}

?>