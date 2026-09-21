<?php

namespace base;

/**
 * 响应抽象基类
 *
 * - 契约以**实例**为准: 状态码 / 返回内容 / 内容类型随实例走, 不使用静态初始化
 * - 渲染与发送需要请求上下文(内容协商读 `Accept` 头, HEAD 不出响应体), 由调用方传入 `Request`
 */
abstract class Response {

    /**
     * 状态码
     * @var int
     */
    protected int $code=200;

    /**
     * 控制器返回数据
     * @var mixed
     */
    protected mixed $controller_return=null;

    /**
     * 返回内容类型
     * @var string
     */
    protected string $contentType='*/*';

    /**
     * 待返回内容
     * @var ?string
     */
    protected ?string $return_content=null;

    /**
     * 获取状态码
     *
     * @access public
     * @return int
     */
    public function getStatusCode(): int {
        return $this->code;
    }

    /**
     * 设置状态码
     *
     * @access public
     * @param int $code 状态码
     * @return void
     */
    public function setStatusCode(int $code): void {
        $this->code=$code;
    }

    /**
     * 获取控制器返回值
     *
     * @access public
     * @return mixed
     */
    public function getControllerReturn(): mixed {
        return $this->controller_return;
    }

    /**
     * 设置控制器返回值
     *
     * @access public
     * @param mixed $return 控制器返回值
     * @return void
     */
    public function setControllerReturn(mixed $return): void {
        $this->controller_return=$return;
    }

    /**
     * 获取返回内容类型
     *
     * @access public
     * @return string
     */
    public function getContentType(): string {
        return $this->contentType;
    }

    /**
     * 设置返回内容类型
     *
     * @access public
     * @param string $type 类型
     * @return void
     */
    public function setContentType(string $type): void {
        $this->contentType=$type;
    }

    /**
     * 设置返回类型为json
     *
     * @access public
     * @param mixed $data 返回的数据
     * @return mixed
     */
    public function json(mixed $data=null): mixed {
        $this->contentType='application/json';
        return $data;
    }

    /**
     * 设置返回类型为html
     *
     * @access public
     * @param array|object|string|int|bool|null $content 返回内容
     * @return mixed
     */
    public function html(null|string|int|bool $content=null): mixed {
        $this->contentType='text/html';
        return $content;
    }

    /**
     * 设置返回类型为text
     *
     * @access public
     * @param array|object|string|int|bool|null $content 返回内容
     * @return mixed
     */
    public function text(null|string|int|bool $content=null): mixed {
        $this->contentType='text/plain';
        return $content;
    }

    /**
     * 获取最终返回内容
     *
     * @access public
     * @return ?string
     */
    public function getReturnContent(): ?string {
        return $this->return_content;
    }

    /**
     * 设置最终返回内容
     *
     * @access public
     * @param string|null $content 内容(null 表示未设置)
     * @return void
     */
    public function setReturnContent(?string $content): void {
        $this->return_content=$content;
    }

    /**
     * 获取一个标准的返回类型
     *
     * @access public
     * @param string|null $type 类型
     * @return string
     */
    abstract public function getStandardContentType(
        ?string $type=null
    ): string;

    /**
     * 获取Header
     *
     * @access public
     * @param string $name Header名
     * @return string
     */
    abstract public function getHeader(string $name): string;

    /**
     * 设置Header
     *
     * @access public
     * @param string|array $params 参数(string时为header名,array时为header数组)
     * @param string $value $params 参数为数组时此参数无效)
     * @return void
     */
    abstract public function setHeader(
        string|array $params,
        string $value
    ): void;

    /**
     * 发送请求头和状态码
     *
     * @access public
     * @return void
     */
    abstract public function sendHeaders(): void;

    /**
     * 获取Cookie
     *
     * @access public
     * @param string $name Cookie名
     * @return string
     */
    abstract public function getCookie(string $name): string;

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
    abstract public function setCookie(
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
     * @param Request $request 请求对象(内容协商读其`Accept`头)
     * @return string
     */
    abstract public function render(Request $request): string;

    /**
     * 结束响应并发送数据
     *
     * @access public
     * @param Request $request 请求对象(HEAD请求只发送头部)
     * @return void
     */
    abstract public function send(Request $request): void;

}
