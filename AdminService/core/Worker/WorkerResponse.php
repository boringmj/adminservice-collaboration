<?php

namespace AdminService\Worker;

/**
 * 常驻 worker 响应
 *
 * - 承载状态、响应头、Cookie 与正文, 由驱动写入具体协议
 *
 * @access public
 * @package AdminService\Worker
 * @version 1.0.0
 */
final class WorkerResponse {

    /**
     * 构造方法
     *
     * @access public
     * @param int $status 状态码
     * @param array<string,array<int,string>> $headers 响应头
     * @param array<string,array{
     *     value:string,
     *     expire:int|null,
     *     path:string|null,
     *     domain:string|null,
     *     secure:bool|null,
     *     httponly:bool|null
     * }> $cookies Cookie
     * @param string $body 正文
     */
    public function __construct(
        private int $status,
        private array $headers=array(),
        private array $cookies=array(),
        private string $body=''
    ) {
    }

    /**
     * 状态码
     *
     * @access public
     * @return int
     */
    public function status(): int {
        return $this->status;
    }

    /**
     * 响应头
     *
     * @access public
     * @return array<string,array<int,string>>
     */
    public function headers(): array {
        return $this->headers;
    }

    /**
     * Cookie
     *
     * @access public
     * @return array<string,array{
     *     value:string,
     *     expire:int|null,
     *     path:string|null,
     *     domain:string|null,
     *     secure:bool|null,
     *     httponly:bool|null
     * }>
     */
    public function cookies(): array {
        return $this->cookies;
    }

    /**
     * 正文
     *
     * @access public
     * @return string
     */
    public function body(): string {
        return $this->body;
    }

}
