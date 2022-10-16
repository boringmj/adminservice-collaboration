<?php

namespace AdminService;

use AdminService\Config;

final class Cookie {
    
        /**
         * 通过数组的方式设置cookie
         * 
         * @access public
         * @param array $data 数据
         * @return void
         */
        public function setByArray(array $data): void {
            foreach($data as $key=>$value)
                $this->set(
                    $key,
                    $value['value']??'',
                    $value['expire']??null,
                    $value['path']??null,
                    $value['domain']??null,
                    $value['secure']??null,
                    $value['httponly']??null
                );
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
        public static function set(
            string $name,mixed $value,
            ?int $expire=null,?string $path=null,?string $domain=null,
            ?bool $secure=null,?bool $httponly=null
        ): void {
                $default=array(
                    'prefix'=>Config::get('cookie.prefix'),
                    'expire'=>Config::get("cookie.expire",3600),
                    'path'=>Config::get("cookie.path",""),
                    'domain'=>Config::get("cookie.domain",""),
                    'secure'=>Config::get("cookie.secure",false),
                    'httponly'=>Config::get("cookie.httponly",false),
                );
            setcookie(
                $default['prefix'].$name,
                $value,
                $expire??$default['expire'],
                $path??$default['path'],
                $domain??$default['domain'],
                $secure??$default['secure'],
                $httponly??$default['httponly']
            );
        }
}

?>