<?php

namespace base;

abstract class Request {

    /**
     * ALL参数
     * @var int
     */
    const ALL_PARAM=0;

    /**
     * GET参数
     * @var int
     */
    const GET_PARAM=1;
    
    /**
     * POST参数
     * @var int
     */
    const POST_PARAM=2;
     
    /**
     * COOKIE参数
     * @var int
     */
    const COOKIE_PARAM=4;

    /**
     * 初始化请求
     *
     * @access public
     * @return void
     */
    abstract static public function init(): void;

    /**
     * 获取上传的文件信息,
     * 传入字段名则返回`AbstractUploadFiles`,
     * 不传入则返回`AbstractUploadFilesForm`
     * 
     * @access public
     * @param string|null $name 字段名(null时获取全部)
     * @return AbstractUploadFilesForm|AbstractUploadFiles
     */
    abstract static public function getUploadFiles(
        ?string $name=null
    ): AbstractUploadFilesForm|AbstractUploadFiles;

    /**
     * 设置Cookie信息(为中间件修改提供便利,修改有效周期为本次请求,不提供跨会话持久化)
     *
     * @access public
     * @param string|array $params 参数名或参数组
     * @param string $value Cookie值($params 参数为数组时此参数无效)
     * @return void
     */
    abstract static public function setCookie(
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
    abstract static public function getCookie(
        string $name,
        mixed $default=null
    ): mixed;

    /**
     * 获取全部Cookie参数
     * 
     * @access public
     * @return array
     */
    abstract static public function getCookies(): array;

    /**
     * 设置Header信息(为中间件修改提供便利,修改有效周期为本次请求,不提供跨会话持久化)
     * 
     * @access public
     * @param string|array $params 参数名或参数组
     * @param string $value Cookie值($params 参数为数组时此参数无效)
     * @return void
     */
    abstract static public function setHeader(
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
    abstract static public function getHeader(
        string $name,
        mixed $default=null
    ): mixed;

    /**
     * 获取全部Header参数
     * 
     * @access public
     * @return array
     */
    abstract static public function getHeaders(): array;

    /**
     * 设置Input参数
     *
     * @access public
     * @param string|array $params 参数
     * @param string $value Cookie值($params 参数为数组时此参数无效)
     * @return void
     */
    abstract static public function setInput(
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
    abstract static public function getInput(
        string $name,
        mixed $default=null
    ): mixed;

    /**
     * 获取全部Input参数
     * 
     * @access public
     * @return array
     */
    abstract static public function getInputs(): array;

    /**
     * 获取原始Input数据
     * 
     * @access public
     * @return string
     */
    abstract static public function getRawInput(): string;

    /**
     * 设置Server参数
     *
     * @access public
     * @param string|array $params 参数名或参数组
     * @param mixed $value Cookie值($params 参数为数组时此参数无效)
     * @return void
     */
    abstract static public function setServer(
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
    abstract static public function getServer(
        string $name,
        mixed $default=null
    ): mixed;

    /**
     * 获取全部Server参数
     * 
     * @access public
     * @return array
     */
    abstract static public function getServers(): array;

    /**
     * 设置GET参数
     *
     * @access public
     * @param string|array $params 参数名或参数组
     * @param mixed $value Cookie值($params 参数为数组时此参数无效)
     * @return void
     */
    abstract static public function setGet(
        string|array $params,
        mixed $value=null
    ): void;

    /**
     * 获取GET参数
     * 
     * @access public
     * @param string $name 参数名
     * @param mixed $default 默认值
     * @return mixed
     */
    abstract static public function getGet(
        string $name,
        mixed $default=null
    ): mixed;

    /**
     * 获取全部GET参数
     * 
     * @access public
     * @return array
     */
    abstract static public function getGets(): array;

    /**
     * 设置POST参数
     *
     * @access public
     * @param string|array $params 参数名或参数组
     * @param mixed $value Cookie值($params 参数为数组时此参数无效)
     * @return void
     */
    abstract static public function setPost(
        string|array $params,
        mixed $value=null
    ): void;

    /**
     * 获取POST参数
     * 
     * @access public
     * @param string $name 参数名
     * @param mixed $default 默认值
     * @return mixed
     */
    abstract static public function getPost(
        string $name,
        mixed $default=null
    ): mixed;

    /**
     * 获取全部POST参数
     * 
     * @access public
     * @return array
     */
    abstract static public function getPosts(): array;

    /**
     * 设置Session参数
     *
     * @access public
     * @param string|array $params 参数名或参数组
     * @param string $value Cookie值($params 参数为数组时此参数无效) 
     * @return void
     */
    abstract static public function setSession(
        string|array $params,
        string $value=''
    ): void;

    /**
     * 获取Session参数
     * 
     * @access public
     * @param string $name 参数名
     * @param mixed $default 默认值
     * @return mixed
     */
    abstract static public function getSession(
        string $name,
        mixed $default=null
    ): mixed;

    /**
     * 获取请求参数键名
     * 
     * @access public
     * @param int $type 参数类型
     * @return array
     */
    abstract static public function getParamKeys(
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
    abstract static public function getParam(
        string $name,
        int $type=self::ALL_PARAM,
        mixed $default=null
    ): mixed;

    /**
     * 通过键名设置请求参数
     * 
     * @access public
     * @param string|array $params 参数名或参数组
     * @param mixed $value Cookie值($params 参数为数组时此参数无效)
     * @param int $type 参数类型
     * @return void
     */
    abstract static public function setParam(
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
    abstract static public function removeParam(
        string|array $params,
        int $type=self::ALL_PARAM
    ): void;

    /**
     * 获取上传文件实例
     * 
     * @access public
     * @return AbstractUploadFilesForm
     */
    abstract static public function getUploadFilesInstance(): AbstractUploadFilesForm;

    /**
     * 获取Session实例
     * 
     * @access public
     * @return AbstractSession
     */
    abstract static public function getSessionInstance(): AbstractSession;

}