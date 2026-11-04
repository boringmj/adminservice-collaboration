<?php

namespace Tests\Fixtures;

use base\Cookie;

/**
 * 记录待下发 Cookie 的测试实现
 */
final class RecordingCookie extends Cookie {

    /**
     * 最近一次批量下发数据
     * @var array<string,mixed>
     */
    public array $data=array();

    /**
     * 通过数组的方式设置cookie
     *
     * @param array<string,mixed> $data 数据
     * @return void
     */
    public function setByArray(array $data): void {
        $this->data=$data;
    }

    /**
     * 设置Cookie
     *
     * @param string $name 名称
     * @param mixed $value 值
     * @param int|null $expire 过期时间
     * @param string|null $path 路径
     * @param string|null $domain 域名
     * @param bool|null $secure 是否仅https
     * @param bool|null $httponly 是否仅http
     * @return void
     */
    public function set(
        string $name,mixed $value,
        ?int $expire=null,?string $path=null,?string $domain=null,
        ?bool $secure=null,?bool $httponly=null
    ): void {
    }

}
