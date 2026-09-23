<?php

namespace AdminService;

use base\Cookie as BaseCookie;
use base\ConfigInterface;

use function is_array;
use function setcookie;
use function time;

/**
 * Cookie 类
 *
 * - **为什么这里仍按进程级配置取值**: 契约 `base\Cookie` 的方法都是 `abstract static`,
 *   本类只能实现成静态方法 —— 静态 API 没有实例, 也就无法用构造注入拿配置实例。
 *   容器可用时读容器里登记的那一份, 否则回落门面(同一进程内两者指向同一实例, 见 `Config::set()` 的同步)。
 *   这是"无法注入"的**结构性**原因。
 */
final class Cookie extends BaseCookie {

    /**
     * 取配置(静态 API 的取值口)
     *
     * - 当前容器(请求进行中即请求级容器)里登记了配置实例就读它, 这样作用域内的配置能生效
     * - 容器未就绪(引导期)或未登记时回落门面当前那份
     *
     * @access private
     * @return ConfigInterface
     */
    private static function config(): ConfigInterface {
        $container=App::hasInstance()?App::getInstance():null;
        if($container!==null&&$container->hasInstance(ConfigInterface::class))
            return $container->get(ConfigInterface::class);
        return Config::repository()??new Repository(array());
    }

    /**
     * 通过数组的方式设置cookie
     *
     * @access public
     * @param array<string,mixed> $data 数据
     * @return void
     */
    public static function setByArray(array $data): void {
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
     * @param int|null $expire 过期时间
     * @param string|null $path 路径
     * @param string|null $domain 域名
     * @param bool $secure 是否仅https
     * @param bool $httponly 是否仅http
     * @return void
     */
    public static function set(
        string $name,mixed $value,
        ?int $expire=null,?string $path=null,?string $domain=null,
        ?bool $secure=null,?bool $httponly=null
    ): void {
        setcookie(
            self::config()->get('cookie.prefix','').$name,
            $value,
            time()+($expire??self::config()->get('cookie.expire',3600)),
            $path??self::config()->get('cookie.path',''),
            $domain??self::config()->get('cookie.domain',''),
            $secure??self::config()->get('cookie.secure',false),
            $httponly??self::config()->get('cookie.httponly',false)
        );
    }

}