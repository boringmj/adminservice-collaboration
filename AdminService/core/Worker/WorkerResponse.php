<?php

namespace AdminService\Worker;

/**
 * 常驻 worker 响应
 *
 * - 承载状态、响应头、Cookie 与正文或流式生成器, 由驱动写入具体协议
 *
 * @access public
 * @package AdminService\Worker
 * @version 1.0.0
 */
final class WorkerResponse {

    /**
     * 流式响应生成器
     * @var callable(callable(string):void):void|null
     */
    private $stream=null;

    /**
     * 流式响应遇到客户端断开时的处理方式
     * @var 'stop'|'continue'
     */
    private string $stream_on_disconnect='stop';

    /**
     * 构造方法
     *
     * @access public
     * @param int $status 状态码
     * @param array<string,array<int,string>> $headers 响应头
     * @param array<string,array{
     *     value:string,
     *     expire:int,
     *     path:string,
     *     domain:string,
     *     secure:bool,
     *     httponly:bool
     * }> $cookies Cookie
     * @param string $body 正文
     * @param callable(callable(string):void):void|null $stream 流式响应生成器(非 null 时正文为空)
     * @param 'stop'|'continue' $stream_on_disconnect 流式响应断开处理方式
     */
    public function __construct(
        private int $status,
        private array $headers=array(),
        private array $cookies=array(),
        private string $body='',
        ?callable $stream=null,
        string $stream_on_disconnect='stop'
    ) {
        $this->stream=$stream;
        $this->stream_on_disconnect=$stream_on_disconnect==='continue'?'continue':'stop';
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
     *     expire:int,
     *     path:string,
     *     domain:string,
     *     secure:bool,
     *     httponly:bool
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

    /**
     * 流式响应生成器
     *
     * @access public
     * @return callable(callable(string):void):void|null
     */
    public function stream(): ?callable {
        return $this->stream;
    }

    /**
     * 流式响应遇到客户端断开时的处理方式
     *
     * @access public
     * @return 'stop'|'continue'
     */
    public function streamOnDisconnect(): string {
        return $this->stream_on_disconnect;
    }

}
