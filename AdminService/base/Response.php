<?php

namespace base;

use function func_num_args;
use function var_export;

/**
 * 响应抽象基类
 *
 * - 契约以**实例**为准: 状态码 / 返回内容 / 内容类型随实例走, 不使用静态初始化
 * - 读写合一: 不传值即读取, 传值即写入(空值同样合法的成员用 `func_num_args()` 判定)
 * - 渲染只依赖自身已就位的内容类型; 与请求相关的部分(内容协商、HEAD)集中在 {@see prepare()}
 */
abstract class Response {

    /**
     * 状态码
     * @var int
     */
    protected int $code=200;

    /**
     * 控制器返回值(渲染前的原始返回)
     * @var mixed
     */
    protected mixed $controller_return=null;

    /**
     * 返回内容类型
     * @var string
     */
    protected string $contentType='*/*';

    /**
     * 渲染后的响应内容
     * @var ?string
     */
    protected ?string $return_content=null;

    /**
     * 流式响应生成器
     *
     * - 接收 `callable(string):void` 写入器, 每调用一次输出一段正文; 客户端断开时写入器抛异常
     * - 设置后优先于普通返回内容
     *
     * @var callable(callable(string):void):void|null
     */
    protected $stream_producer=null;

    /**
     * 流式响应遇到客户端断开时的处理方式
     *
     * - `stop`: 写入器抛异常, 生成器停止
     * - `continue`: 写入器改为空操作, 生成器继续执行完毕
     *
     * @var 'stop'|'continue'
     */
    protected string $stream_on_disconnect='stop';

    /**
     * 获取或设置状态码
     *
     * @access public
     * @param int|null $code 状态码(null 时仅读取)
     * @return int
     */
    public function status(?int $code=null): int {
        if($code!==null)
            $this->code=$code;
        return $this->code;
    }

    /**
     * 获取或设置返回内容类型
     *
     * - 改写后已渲染内容失效
     *
     * @access public
     * @param string|null $type 类型(null 时仅读取)
     * @return string
     */
    public function contentType(?string $type=null): string {
        if($type!==null) {
            $this->contentType=$type;
            $this->return_content=null;
        }
        return $this->contentType;
    }

    /**
     * 获取或设置控制器返回值
     *
     * - 不传参数为读取; 传参数为写入(可写 `null`), 已渲染内容随之失效
     *
     * @access public
     * @param mixed $content 控制器返回值
     * @return mixed
     */
    public function body(mixed $content=null): mixed {
        if(func_num_args()>0) {
            $this->controller_return=$content;
            $this->return_content=null;
        }
        return $this->controller_return;
    }

    /**
     * 获取或设置渲染后的响应内容
     *
     * - 不传参数为读取; 传参数为写入(可写 `null`); 已渲染时 {@see render()} 直接复用该值
     *
     * @access public
     * @param string|null $content 渲染后的内容
     * @return ?string
     */
    public function rendered(?string $content=null): ?string {
        if(func_num_args()>0) {
            $this->return_content=$content;
            if($content!==null)
                $this->stream_producer=null;
        }
        return $this->return_content;
    }

    /**
     * 设置返回类型为json
     *
     * @access public
     * @param mixed $data 返回的数据
     * @return mixed
     */
    public function json(mixed $data=null): mixed {
        $this->contentType('application/json');
        return $data;
    }

    /**
     * 设置返回类型为html
     *
     * @access public
     * @param array|object|string|int|float|bool|null $content 返回内容
     * @return mixed
     */
    public function html(mixed $content=null): mixed {
        $this->contentType('text/html');
        return $content;
    }

    /**
     * 设置返回类型为text
     *
     * @access public
     * @param array|object|string|int|float|bool|null $content 返回内容
     * @return mixed
     */
    public function text(mixed $content=null): mixed {
        $this->contentType('text/plain');
        return $content;
    }

    /**
     * 设置流式响应
     *
     * - 生成器接收 `callable(string):void` 写入器, 每调用一次输出一段正文
     * - `on_disconnect=stop` 时客户端断开由写入器抛异常; `continue` 时写入器改为空操作
     * - 设置后优先于普通返回内容; HEAD 与禁止正文的状态码不会调用生成器
     *
     * @access public
     * @param callable(callable(string):void):void $producer 生成器, 参数为正文写入器
     * @param string|null $content_type 内容类型(null = 沿用当前类型)
     * @param array{on_disconnect?:'stop'|'continue'} $options 流式选项
     * @return static
     * @throws \InvalidArgumentException `on_disconnect` 取值无效
     */
    public function stream(callable $producer,?string $content_type=null,array $options=array()): static {
        $mode=$options['on_disconnect']??'stop';
        if($mode!=='stop'&&$mode!=='continue')
            throw new \InvalidArgumentException('Unknown stream option on_disconnect: '.var_export($mode,true).'; expected stop or continue.');
        if($content_type!==null)
            $this->contentType($content_type);
        $this->controller_return=null;
        $this->return_content=null;
        $this->stream_producer=$producer;
        $this->stream_on_disconnect=$mode;
        return $this;
    }

    /**
     * 是否设置了流式响应
     *
     * @access public
     * @return bool
     */
    public function hasStream(): bool {
        return $this->stream_producer!==null;
    }

    /**
     * 清除流式响应
     *
     * - 供错误出口等改回普通正文的调用方使用, 不影响已设置的返回内容
     *
     * @access public
     * @return void
     */
    public function clearStream(): void {
        $this->stream_producer=null;
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

    /**
     * 获取流式响应生成器
     *
     * @access public
     * @return callable(callable(string):void):void|null
     */
    public function streamProducer(): ?callable {
        return $this->stream_producer;
    }

    /**
     * 获取一个标准的返回类型
     *
     * @access public
     * @param string|null $type 类型(null 时取当前内容类型)
     * @return string
     */
    abstract public function getStandardContentType(
        ?string $type=null
    ): string;

    /**
     * 按请求准备响应
     *
     * - 内容类型未显式指定时按 `Accept` 头协商; 请求方法为 HEAD 时只发送响应头
     * - 与请求相关的职责集中在此, 渲染与发送不再依赖请求对象
     *
     * @access public
     * @param Request $request 请求对象
     * @return void
     */
    abstract public function prepare(Request $request): void;

    /**
     * 获取或设置单个Header
     *
     * @access public
     * @param string $name Header名
     * @param string|array|null $value 值(数组为多值; null 时仅读取)
     * @return string
     */
    abstract public function header(
        string $name,
        string|array|null $value=null
    ): string;

    /**
     * 追加一个Header值(同名多值, 如 `Vary` / `Accept`)
     *
     * @access public
     * @param string $name Header名
     * @param string $value 值
     * @return void
     */
    abstract public function addHeader(string $name,string $value): void;

    /**
     * 批量设置Header
     *
     * @access public
     * @param array<string,string|array> $headers Header数组(值为数组即多值)
     * @return void
     */
    abstract public function headers(array $headers): void;

    /**
     * 获取或设置单个Cookie
     *
     * - 不传值即读取; 传值即下发, 其余参数为该Cookie的属性
     *
     * @access public
     * @param string $name Cookie名
     * @param string|null $value Cookie值(null 时仅读取)
     * @param int|null $expire 过期时间
     * @param string|null $path 路径
     * @param string|null $domain 域名
     * @param bool $secure 是否安全传输
     * @param bool $httponly 是否仅http传输
     * @return string
     */
    abstract public function cookie(
        string $name,
        ?string $value=null,
        ?int $expire=null,
        ?string $path=null,
        ?string $domain=null,
        ?bool $secure=null,
        ?bool $httponly=null
    ): string;

    /**
     * 批量设置Cookie
     *
     * - 支持 `name => value` 与 `name => array(value / expire / path / domain / secure / httponly)` 两种写法
     *
     * @access public
     * @param array<string,mixed> $cookies Cookie数组
     * @return void
     */
    abstract public function cookies(array $cookies): void;

    /**
     * 发送请求头和状态码
     *
     * @access public
     * @return void
     */
    abstract public function sendHeaders(): void;

    /**
     * 渲染结果
     *
     * - 结果会缓存, 直到控制器返回值或内容类型被改写
     *
     * @access public
     * @return string
     */
    abstract public function render(): string;

    /**
     * 结束响应并发送数据
     *
     * @access public
     * @return void
     */
    abstract public function send(): void;

}
