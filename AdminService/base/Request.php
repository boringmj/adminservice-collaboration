<?php

namespace base;

/**
 * 请求抽象基类
 *
 * - 契约以**实例**为准: 输入在构造时一次就位, 不使用静态初始化
 * - 构造函数约定见 {@see \AdminService\HttpRequest::__construct}(可选输入源数组, 缺省读超全局)
 * - 输入区(query / post / cookie / header / input / server): 传参数名取单个, 不传取该区全部
 * - attributes 区存放程序内部附加数据(如路由参数), 不属于输入, 但在 {@see param()} 中优先于任一输入区
 * - 相关方法(修改请求参数)为中间件提供便利, 有效期仅限本次请求
 */
abstract class Request {

    /**
     * ALL参数
     * @var int
     */
    public const ALL_PARAM=0;

    /**
     * GET参数
     * @var int
     */
    public const GET_PARAM=1;

    /**
     * POST参数
     * @var int
     */
    public const POST_PARAM=2;

    /**
     * COOKIE参数
     * @var int
     */
    public const COOKIE_PARAM=4;

    /**
     * 获取查询串参数(不传参数名时返回全部)
     *
     * @access public
     * @param string|null $name 参数名(null时获取全部)
     * @param mixed $default 默认值(仅取单个时生效)
     * @return mixed
     */
    abstract public function query(?string $name=null,mixed $default=null): mixed;

    /**
     * 获取POST参数(不传参数名时返回全部)
     *
     * @access public
     * @param string|null $name 参数名(null时获取全部)
     * @param mixed $default 默认值(仅取单个时生效)
     * @return mixed
     */
    abstract public function post(?string $name=null,mixed $default=null): mixed;

    /**
     * 获取Cookie参数(不传参数名时返回全部)
     *
     * @access public
     * @param string|null $name 参数名(null时获取全部)
     * @param mixed $default 默认值(仅取单个时生效)
     * @return mixed
     */
    abstract public function cookie(?string $name=null,mixed $default=null): mixed;

    /**
     * 获取Header参数(不传参数名时返回全部, 键名大小写不敏感)
     *
     * @access public
     * @param string|null $name 参数名(null时获取全部)
     * @param mixed $default 默认值(仅取单个时生效)
     * @return mixed
     */
    abstract public function header(?string $name=null,mixed $default=null): mixed;

    /**
     * 获取Input参数(由Content-Type对应的处理器解析出的请求体, 不传参数名时返回全部)
     *
     * @access public
     * @param string|null $name 参数名(null时获取全部)
     * @param mixed $default 默认值(仅取单个时生效)
     * @return mixed
     */
    abstract public function input(?string $name=null,mixed $default=null): mixed;

    /**
     * 获取Server参数(不传参数名时返回全部)
     *
     * @access public
     * @param string|null $name 参数名(null时获取全部)
     * @param mixed $default 默认值(仅取单个时生效)
     * @return mixed
     */
    abstract public function server(?string $name=null,mixed $default=null): mixed;

    /**
     * 获取原始请求体
     *
     * @access public
     * @return string
     */
    abstract public function rawInput(): string;

    /**
     * 获取请求方法(大写, 缺省为GET)
     *
     * @access public
     * @return string
     */
    abstract public function method(): string;

    /**
     * 获取请求URI(含查询串)
     *
     * @access public
     * @return string
     */
    abstract public function uri(): string;

    /**
     * 获取请求路径(不含查询串, 去掉末尾斜杠, 缺省为`/`)
     *
     * @access public
     * @return string
     */
    abstract public function path(): string;

    /**
     * 判断客户端是否接受某内容类型
     *
     * - 依据 `Accept` 头, 支持子类通配(`type/*`)与全通配; 无 `Accept` 头视为接受任意类型
     *
     * @access public
     * @param string $type 内容类型
     * @return bool
     */
    abstract public function accepts(string $type): bool;

    /**
     * 判断客户端是否明确要求JSON
     *
     * @access public
     * @return bool
     */
    abstract public function wantsJson(): bool;

    /**
     * 判断本次请求是否期望JSON响应
     *
     * - `X-Requested-With: XMLHttpRequest`(Ajax)或明确要求JSON时为真
     * - 适合统一错误响应体: `if($request->expectsJson()) { ... }`
     *
     * @access public
     * @return bool
     */
    abstract public function expectsJson(): bool;

    /**
     * 设置查询串参数
     *
     * @access public
     * @param string|array $params 参数名或参数组
     * @param mixed $value 值($params 参数为数组时此参数无效)
     * @return void
     */
    abstract public function setQuery(string|array $params,mixed $value=null): void;

    /**
     * 设置POST参数
     *
     * @access public
     * @param string|array $params 参数名或参数组
     * @param mixed $value 值($params 参数为数组时此参数无效)
     * @return void
     */
    abstract public function setPost(string|array $params,mixed $value=null): void;

    /**
     * 设置Cookie信息(仅修改本请求实例的Cookie容器, 不同步到`Response`)
     *
     * @access public
     * @param string|array $params 参数名或参数组
     * @param string $value Cookie值($params 参数为数组时此参数无效)
     * @return void
     */
    abstract public function setCookie(string|array $params,string $value=''): void;

    /**
     * 设置Header信息(仅修改本请求实例的Header容器, 不同步到`Response`)
     *
     * @access public
     * @param string|array $params 参数名或参数组
     * @param string $value Header值($params 参数为数组时此参数无效)
     * @return void
     */
    abstract public function setHeader(string|array $params,string $value=''): void;

    /**
     * 设置Input参数
     *
     * @access public
     * @param string|array $params 参数名或参数组
     * @param string $value Input值($params 参数为数组时此参数无效)
     * @return void
     */
    abstract public function setInput(string|array $params,string $value=''): void;

    /**
     * 设置Server参数
     *
     * @access public
     * @param string|array $params 参数名或参数组
     * @param mixed $value 值($params 参数为数组时此参数无效)
     * @return void
     */
    abstract public function setServer(string|array $params,mixed $value=null): void;

    /**
     * 通过键名获取请求参数
     *
     * - 属性(如路由参数)优先, 其余按 `request.default.param.order` 的顺序检索
     *
     * @access public
     * @param string $name 参数名
     * @param int $type 参数类型
     * @param mixed $default 默认值
     * @return mixed
     */
    abstract public function param(
        string $name,
        int $type=self::ALL_PARAM,
        mixed $default=null
    ): mixed;

    /**
     * 获取请求参数键名
     *
     * @access public
     * @param int $type 参数类型
     * @return array
     */
    abstract public function paramKeys(int $type=self::ALL_PARAM): array;

    /**
     * 通过键名设置请求参数
     *
     * @access public
     * @param string|array $params 参数名或参数组
     * @param mixed $value 值($params 参数为数组时此参数无效)
     * @param int $type 参数类型
     * @return void
     */
    abstract public function setParam(
        string|array $params,
        mixed $value=null,
        int $type=self::ALL_PARAM
    ): void;

    /**
     * 通过键名删除请求参数
     *
     * @access public
     * @param string|array $params 参数名或参数组
     * @param int $type 参数类型
     * @return void
     */
    abstract public function removeParam(
        string|array $params,
        int $type=self::ALL_PARAM
    ): void;

    /**
     * 设置请求属性(程序内部附加的数据, 如路由参数)
     *
     * @access public
     * @param string $name 属性名
     * @param mixed $value 属性值
     * @return void
     */
    abstract public function setAttribute(string $name,mixed $value): void;

    /**
     * 获取请求属性
     *
     * @access public
     * @param string $name 属性名
     * @param mixed $default 默认值
     * @return mixed
     */
    abstract public function attribute(string $name,mixed $default=null): mixed;

    /**
     * 获取全部请求属性
     *
     * @access public
     * @return array
     */
    abstract public function attributes(): array;

    /**
     * 获取上传的文件信息,
     * 传入字段名则返回`AbstractUploadFiles`,
     * 不传入则返回`AbstractUploadFilesForm`
     *
     * @access public
     * @param string|null $name 字段名(null时获取全部)
     * @return AbstractUploadFilesForm|AbstractUploadFiles
     */
    abstract public function file(
        ?string $name=null
    ): AbstractUploadFilesForm|AbstractUploadFiles;

    /**
     * 获取表单文件实例(全部上传文件)
     *
     * @access public
     * @return AbstractUploadFilesForm
     */
    abstract public function files(): AbstractUploadFilesForm;

}
