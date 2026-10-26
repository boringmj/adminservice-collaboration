<?php

namespace AdminService;

use base\Response;

use function implode;

/**
 * HTTP 错误响应
 *
 * - 描述状态码、正文与 Header, 由调用方应用到当前请求的 Response
 * - 不负责发送响应, 也不查容器
 */
final class HttpError {

    /**
     * @param array<string,string|array<string>> $headers 响应头
     */
    private function __construct(
        private int $status,
        private mixed $body,
        private array $headers=array()
    ) {}

    /**
     * 404 Not Found
     *
     * @access public
     * @return self
     */
    public static function notFound(): self {
        return new self(404,'404 Not Found');
    }

    /**
     * 405 Method Not Allowed
     *
     * @access public
     * @param array<string> $allowed 允许的请求方法
     * @return self
     */
    public static function methodNotAllowed(array $allowed): self {
        return new self(405,'405 Method Not Allowed',array(
            'Allow'=>implode(', ',$allowed)
        ));
    }

    /**
     * 应用到响应
     *
     * @access public
     * @param Response $response 响应实例
     * @return void
     */
    public function apply(Response $response): void {
        $response->status($this->status);
        foreach($this->headers as $name=>$value)
            $response->header($name,$value);
        $response->body($this->body);
    }

}
