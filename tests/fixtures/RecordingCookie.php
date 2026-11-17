<?php

namespace Tests\Fixtures;

use base\Cookie;
use base\CookieOptions;

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
     * 批量设置 Cookie
     *
     * @param array<string,CookieOptions|string|array<string,mixed>> $cookies 数据
     * @return void
     */
    public function setAll(array $cookies): void {
        $this->data=$cookies;
    }

    /**
     * 构建 Cookie(本实现原样返回)
     *
     * @param string $name 名称
     * @param CookieOptions|string $value 属性或值
     * @return array{name:string,value:CookieOptions|string}
     */
    public function build(string $name,CookieOptions|string $value): array {
        return array('name'=>$name,'value'=>$value);
    }

    /**
     * 批量构建 Cookie(本实现原样返回)
     *
     * @param array<string,CookieOptions|string|array<string,mixed>> $cookies 数据
     * @return array<string,CookieOptions|string|array<string,mixed>>
     */
    public function buildAll(array $cookies): array {
        return $cookies;
    }

    /**
     * 设置 Cookie(不发送)
     *
     * @param string $name 名称
     * @param CookieOptions|string $value 属性或值
     * @return void
     */
    public function set(string $name,CookieOptions|string $value): void {
    }

    /**
     * 删除 Cookie(不发送)
     *
     * @param string $name 名称
     * @param CookieOptions|null $options 属性
     * @return void
     */
    public function delete(string $name,?CookieOptions $options=null): void {
    }

}
