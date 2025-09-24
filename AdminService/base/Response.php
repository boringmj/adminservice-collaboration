<?php

namespace base;

abstract class Response {

    /**
     * 状态码
     * @var int
     */
    static public int $code=200;

    /**
     * 控制器返回数据
     * @var mixed
     */
    static public mixed $controller_return=null;

    /**
     * 返回内容类型
     * @var string
     */
    static protected string $contentType='*/*';

    /**
     * 待返回内容
     * @var ?string
     */
    static protected ?string $return_content=null;

    /**
     * 获取状态码
     * 
     * @access public
     * @return int
     */
    static public function getStatusCode(): int {
        return self::$code;
    }

    /**
     * 设置状态码
     * 
     * @access public
     * @param int $code 状态码
     * @return void
     */
    static public function setStatusCode(int $code): void {
        self::$code=$code;
    }

    /**
     * 获取控制器返回值
     * 
     * @access public
     * @return mixed
     */
    static public function getControllerReturn(): mixed {
        return self::$controller_return;
    }

    /**
     * 设置控制器返回值
     * 
     * @access public
     * @param mixed $return 控制器返回值
     * @return void
     */
    static public function setControllerReturn(mixed $return): void {
        self::$controller_return=$return;
    }

    /**
     * 获取返回内容类型
     * 
     * @access public
     * @return string
     */
    static public function getContentType(): string {
        return self::$contentType;
    }

    /**
     * 设置返回类型为json
     * 
     * @access public
     * @param mixed $data 返回的数据
     * @return mixed
     */
    static public function json(mixed $data=null): mixed {
        self::$contentType='application/json';
        return $data;
    }

    /**
     * 设置返回类型为html
     * 
     * @access public
     * @param array|object|string|int|bool|null $content 返回内容
     * @return mixed
     */
    static public function html(null|string|int|bool $content=null): mixed {
        self::$contentType='text/html';
        return $content;
    }

    /**
     * 设置返回类型为text
     * 
     * @access public
     * @param array|object|string|int|bool|null $content 返回内容
     * @return mixed
     */
    static public function text(null|string|int|bool $content=null): mixed {
        self::$contentType='text/plain';
        return $content;
    }

    /**
     * 设置返回内容类型
     * 
     * @access public
     * @param string $type 类型
     * @return void
     */
    static public function setContentType(string $type): void {
        self::$contentType=$type;
    }

    /**
     * 获取最终返回内容
     * 
     * @access public
     * @return ?string
     */
    static public function getReturnContent(): ?string {
        return self::$return_content;
    }

    /**
     * 设置最终返回内容
     * 
     * @access public
     * @param string $content 内容
     * @return void
     */
    static public function setReturnContent(string $content): void {
        self::$return_content=$content;
    }

    /**
     * 获取一个标准的返回类型
     * 
     * @access public
     * @param string|null $type 类型
     * @return string
     */
    abstract static public function getStandardContentType(
        ?string $type=null
    ): string;

    /**
     * 初始化
     * 
     * @access public
     * @return void
     */
    abstract static public function init(): void;

    /**
     * 获取Header
     * 
     * @access public
     * @param string $name Header名
     * @return string
     */
    abstract static public function getHeader(string $name): string;

    /**
     * 设置Header
     * 
     * @access public
     * @param string|array $params 参数(string时为header名,array时为header数组)
     * @param string $value $params 参数为数组时此参数无效)
     * @return void
     */
    abstract static public function setHeader(
        string|array $params,
        string $value
    ): void;

    /**
     * 发送请求头和状态码
     * 
     * @access public
     * @return void
     */
    abstract static public function sendHeaders(): void;

    /**
     * 获取Cookie
     * 
     * @access public
     * @param string $name Cookie名
     * @return string
     */
    abstract static public function getCookie(string $name): string;

    /**
     * 设置Cookie信息
     *
     * @access public
     * @param string|array $params 参数(string时为cookie名,array时为cookie数组)
     * @param string|null $value Cookie值($params 参数为数组时此参数无效)
     * @param int|null $expire 过期时间($params 参数为数组时此参数无效)
     * @param string|null $path 路径($params 参数为数组时此参数无效)
     * @param string|null $domain 域名($params 参数为数组时此参数无效)
     * @param bool $secure 是否安全传输($params 参数为数组时此参数无效)
     * @param bool $httponly 是否仅http传输($params 参数为数组时此参数无效)
     * @return void
     */
    abstract static public function setCookie(
        string|array $params,
        ?string $value=null,
        ?int $expire=null,
        ?string $path=null,
        ?string $domain=null,
        ?bool $secure=null,
        ?bool $httponly=null
    ): void;

    /**
     * 渲染结果
     * 
     * @access public
     * @return string
     */
    abstract static public function render(): string;

    /**
     * 结束响应并发送数据
     * 
     * @access public
     * @return void
     */
    abstract static public function send(): void;

}