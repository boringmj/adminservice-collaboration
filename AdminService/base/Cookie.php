<?php

namespace base;

/**
 * Cookie 下发契约
 *
 * - 以实例为准: 配置与请求作用域由实现类构造注入
 * - 调用方通过 Response 或容器取得实例
 */
abstract class Cookie {

    /**
     * 通过数组的方式设置cookie
     *
     * @access public
     * @param array<string,mixed> $data 数据
     * @return void
     */
    abstract public function setByArray(array $data): void;

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
    abstract public function set(
        string $name,mixed $value,
        ?int $expire=null,?string $path=null,?string $domain=null,
        ?bool $secure=null,?bool $httponly=null
    ): void;

}
