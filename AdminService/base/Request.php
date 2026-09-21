<?php

namespace base;

/**
 * 请求抽象基类
 *
 * - 契约以**实例**为准: 输入在构造时一次就位, 不使用静态初始化
 * - 构造函数约定见 {@see \AdminService\HttpRequest::__construct}(可选输入源数组, 缺省读超全局)
 * - 参数按来源分区(query / post / cookie / header / input / server), 检索顺序由 `request.default.param.order` 决定
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
     * 获取上传的文件信息,
     * 传入字段名则返回`AbstractUploadFiles`,
     * 不传入则返回`AbstractUploadFilesForm`
     *
     * @access public
     * @param string|null $name 字段名(null时获取全部)
     * @return AbstractUploadFilesForm|AbstractUploadFiles
     */
    abstract public function getUploadFiles(
        ?string $name=null
    ): AbstractUploadFilesForm|AbstractUploadFiles;

    /**
     * 设置Cookie信息(仅修改`Request`容器内缓存,不同步后续请求,不同步到`Response`)
     *
     * @access public
     * @param string|array $params 参数名或参数组
     * @param string $value Cookie值($params 参数为数组时此参数无效)
     * @return void
     */
    abstract public function setCookie(
        string|array $params,
        string $value=''
    ): void;

    /**
     * 获取Cookie参数
     *
     * @access public
     * @param string $name 参数名
     * @param mixed $default 默认值
     * @return mixed
     */
    abstract public function getCookie(
        string $name,
        mixed $default=null
    ): mixed;

    /**
     * 获取全部Cookie参数
     *
     * @access public
     * @return array
     */
    abstract public function getCookies(): array;

    /**
     * 设置Header信息(仅修改`Request`容器内缓存,不同步后续请求,不同步到`Response`)
     *
     * @access public
     * @param string|array $params 参数名或参数组
     * @param string $value Header值($params 参数为数组时此参数无效)
     * @return void
     */
    abstract public function setHeader(
        string|array $params,
        string $value=''
    ): void;

    /**
     * 获取Header参数
     *
     * @access public
     * @param string $name 参数名
     * @param mixed $default 默认值
     * @return mixed
     */
    abstract public function getHeader(
        string $name,
        mixed $default=null
    ): mixed;

    /**
     * 获取全部Header参数
     *
     * @access public
     * @return array
     */
    abstract public function getHeaders(): array;

    /**
     * 设置Input参数
     *
     * @access public
     * @param string|array $params 参数
     * @param string $value Input值($params 参数为数组时此参数无效)
     * @return void
     */
    abstract public function setInput(
        string|array $params,
        string $value=''
    ): void;

    /**
     * 获取Input参数
     *
     * @access public
     * @param string $name 参数名
     * @param mixed $default 默认值
     * @return mixed
     */
    abstract public function getInput(
        string $name,
        mixed $default=null
    ): mixed;

    /**
     * 获取全部Input参数
     *
     * @access public
     * @return array
     */
    abstract public function getInputs(): array;

    /**
     * 获取原始Input数据
     *
     * @access public
     * @return string
     */
    abstract public function getRawInput(): string;

    /**
     * 设置Server参数
     *
     * @access public
     * @param string|array $params 参数名或参数组
     * @param mixed $value Server值($params 参数为数组时此参数无效)
     * @return void
     */
    abstract public function setServer(
        string|array $params,
        mixed $value=null
    ): void;

    /**
     * 获取Server参数
     *
     * @access public
     * @param string $name 参数名
     * @param mixed $default 默认值
     * @return mixed
     */
    abstract public function getServer(
        string $name,
        mixed $default=null
    ): mixed;

    /**
     * 获取全部Server参数
     *
     * @access public
     * @return array
     */
    abstract public function getServers(): array;

    /**
     * 设置Get参数
     *
     * @access public
     * @param string|array $params 参数名或参数组
     * @param mixed $value Get值($params 参数为数组时此参数无效)
     * @return void
     */
    abstract public function setGet(
        string|array $params,
        mixed $value=null
    ): void;

    /**
     * 获取Get参数
     *
     * @access public
     * @param string $name 参数名
     * @param mixed $default 默认值
     * @return mixed
     */
    abstract public function getGet(
        string $name,
        mixed $default=null
    ): mixed;

    /**
     * 获取全部GET参数
     *
     * @access public
     * @return array
     */
    abstract public function getGets(): array;

    /**
     * 设置Post参数
     *
     * @access public
     * @param string|array $params 参数名或参数组
     * @param mixed $value Post值($params 参数为数组时此参数无效)
     * @return void
     */
    abstract public function setPost(
        string|array $params,
        mixed $value=null
    ): void;

    /**
     * 获取Post参数
     *
     * @access public
     * @param string $name 参数名
     * @param mixed $default 默认值
     * @return mixed
     */
    abstract public function getPost(
        string $name,
        mixed $default=null
    ): mixed;

    /**
     * 获取全部POST参数
     *
     * @access public
     * @return array
     */
    abstract public function getPosts(): array;

    /**
     * 获取请求参数键名
     *
     * @access public
     * @param int $type 参数类型
     * @return array
     */
    abstract public function getParamKeys(
        int $type=self::ALL_PARAM
    ): array;

    /**
     * 通过键名获取请求参数
     *
     * @access public
     * @param string $name 参数名
     * @param int $type 参数类型
     * @param mixed $default 默认值
     * @return mixed
     */
    abstract public function getParam(
        string $name,
        int $type=self::ALL_PARAM,
        mixed $default=null
    ): mixed;

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
     * 获取上传文件实例
     *
     * @access public
     * @return AbstractUploadFilesForm
     */
    abstract public function getUploadFilesInstance(): AbstractUploadFilesForm;

}
