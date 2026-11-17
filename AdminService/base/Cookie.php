<?php

namespace base;

/**
 * Cookie 下发契约
 *
 * - 以实例为准: 配置与请求作用域由实现类构造注入
 * - 调用方通过 Response 或容器取得实例
 * - 属性使用 `CookieOptions`; 字符串值表示只设置值
 */
abstract class Cookie {

    /**
     * 设置单个 Cookie
     *
     * @access public
     * @param string $name 名称
     * @param CookieOptions|string $value 属性或值
     * @return void
     */
    abstract public function set(string $name,CookieOptions|string $value): void;

    /**
     * 删除 Cookie
     *
     * @access public
     * @param string $name 名称
     * @param CookieOptions|null $options 保留的路径 / 域名等属性
     * @return void
     */
    abstract public function delete(string $name,?CookieOptions $options=null): void;

    /**
     * 批量设置 Cookie
     *
     * - 值可为 `CookieOptions`、字符串或选项数组
     *
     * @access public
     * @param array<string,CookieOptions|string|array<string,mixed>> $cookies 名称 => 属性
     * @return void
     */
    abstract public function setAll(array $cookies): void;

    /**
     * 构建单个待下发的 Cookie 描述(不发送)
     *
     * @access public
     * @param string $name 名称
     * @param CookieOptions|string $value 属性或值
     * @return array{
     *     value:string,
     *     expire:int,
     *     path:string,
     *     domain:string,
     *     secure:bool,
     *     httponly:bool,
     *     samesite:string
     * }
     */
    abstract public function build(string $name,CookieOptions|string $value): array;

    /**
     * 批量构建待下发的 Cookie 描述(不发送)
     *
     * @access public
     * @param array<string,CookieOptions|string|array<string,mixed>> $cookies 名称 => 属性
     * @return array<string,array{
     *     value:string,
     *     expire:int,
     *     path:string,
     *     domain:string,
     *     secure:bool,
     *     httponly:bool,
     *     samesite:string
     * }>
     */
    abstract public function buildAll(array $cookies): array;

}
