<?php

namespace AdminService;

use base\Cookie as BaseCookie;
use AdminService\Config;

final class Cookie extends BaseCookie {
    
    /**
     * 通过数组的方式设置cookie
     * 
     * @access public
     * @param array $data 数据
     * @return void
     */
    static public function setByArray(array $data): void {
        foreach($data as $key=>$value)
            if(is_array($value))
                self::set(
                    $key,
                    $value['value']??'',
                    $value['expire']??null,
                    $value['path']??null,
                    $value['domain']??null,
                    $value['secure']??null,
                    $value['httponly']??null
                );
            else
                self::set($key,$value);
    }

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
    static public function set(
        string $name,mixed $value,
        ?int $expire=null,?string $path=null,?string $domain=null,
        ?bool $secure=null,?bool $httponly=null
    ): void {
        setcookie(
            Config::get('cookie.prefix','').$name,
            $value,
            time()+($expire??Config::get('cookie.expire',3600)),
            $path??Config::get('cookie.path',''),
            $domain??Config::get('cookie.domain',''),
            $secure??Config::get('cookie.secure',false),
            $httponly??Config::get('cookie.httponly',false)
        );
    }

}

?>