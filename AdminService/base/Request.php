<?php

namespace base;

use AdminService\Config;
use AdminService\App;
use AdminService\Exception;
use \ReflectionException;

abstract class Request {

    /**
     * 请求参数
     * @var array
     */
    static protected array $request_params;

    /**
     * 返回数据的信息
     * @var array
     */
    static protected array $request_info;

    /**
     * Cookie对象
     * @var Cookie
     */
    static protected Cookie $cookie;

    /**
     * 设置返回类型
     * 
     * @access public
     * @param string $type 数据类型(html|json,default:html)
     * @return void
     */
    abstract static public function setReturnType(string $type): void;

    /**
     * 结束运行
     * 
     * @access public
     * @param mixed $data 数据
     * @return void
     */
    abstract static public function requestExit(mixed $data=null): void;

    /**
     * 结束时输出内容
     * 
     * @access public
     * @return void
     */
    abstract static public function requestEcho(): void;

    /**
     * 设置Header
     * 
     * @access public
     * @param string $name 名称
     * @param string $value 值
     * @return bool
     */
    abstract static public function setHeader(string $name,string $value): bool;

    /**
     * 设置或获取COOKIE请求参数(设置Cookie时Cookie将会在本次以及后续请求中生效)
     * 
     * @access public
     * @param int|string|array $params 参数
     * @param mixed $value 值(不为空则设置)
     * @param bool $enforce 是否与 params() 方法同步
     * @return mixed
     */
    abstract static public function cookieParams(int|string|array $params,mixed $value=null,bool $enforce=false): mixed;

    /**
     * 获取上传的文件
     * 
     * @access public
     * @param string $name 字段名
     * @return array
     */
    abstract static public function getUploadFile(string $name): array;

    /**
     * 设置COOKIE参数(设置Cookie时Cookie将会在本次以及后续请求中生效)
     * 
     * @access public
     * @param int|string|array $params 参数
     * @param mixed $value 值(当 $params 为数组时此参数无效)
     * @param bool $enforce 是否与 params() 方法同步
     * @return void
     */
    abstract static public function setCookie(int|string|array $params,mixed $value=null,bool $enforce=false): void;

    /**
     * 添加返回的Cookie信息
     *
     * @access public
     * @param string|array $params 参数(string时为cookie名,array时为cookie数组)
     * @param string $value Cookie值($params 参数为数组时此参数无效)
     * @param int|null $expire 过期时间
     * @param string|null $path 路径
     * @param string|null $domain 域名
     * @return void
     */
    abstract static public function addCookie(string|array $params,string $value,?int $expire=null,?string $path=null,?string $domain=null): void;

    /**
     * 初始化请求
     *
     * @access public
     * @param Cookie|null $cookie Cookie对象
     * @return void
     * @throws Exception|ReflectionException
     */
    final static public function init(?Cookie $cookie=null): void {
        if($cookie===null)
            $cookie=App::get(Cookie::class);
        // 初始化Cookie
        self::$cookie=$cookie;
        // 设置默认返回数据信息
        self::$request_info=array(
            'return_type'=>Config::get('request.default.type','html'),
            'return_header'=>array(),
            'code'=>Config::get('request.default.json.code',1),
            'msg'=>Config::get('request.default.json.msg','success'),
            'data'=>array(),
            'cookie'=>array(),
            'return_data'=>null
        );
    }

    /**
     * 初始化请求参数
     * 
     * @access public
     * @return void
     */
    final static public function paramsInit(): void {
        // 初始化请求参数
        self::$request_params=array(
            '_GET'=>$_GET,
            '_POST'=>$_POST,
            '_COOKIE'=>$_COOKIE,
            '_INPUT'=>file_get_contents('php://input'),
        );
        // 获取 Content-Type 请求头
        $content_type=$_SERVER['CONTENT_TYPE']??'';
        // 判断是否为 application/json
        if(str_contains(strtolower($content_type),'application/json')) {
            // 获取请求数据
            $request_data=self::$request_params['_INPUT'];
            // 判断是否为json数据
            if($request_data!==false&&$request_data!=='') {
                // 解析json数据
                $request_data=json_decode($request_data,true);
                // 判断是否解析成功
                if($request_data!==null)
                    self::$request_params['_POST']=$request_data;
            }
        }
        $_GET=array();
        $_POST=array();
        $_COOKIE=array();
        // 按CGP顺序初始化请求参数
        self::$request_params=array_merge(
            self::$request_params['_COOKIE'],
            self::$request_params['_GET'],
            self::$request_params['_POST'],
            self::$request_params
        );
    }

    /**
     * 获取或设置请求参数(传入数组则设置请求参数)
     * 
     * @access public
     * @param int|string|array $params 参数
     * @param mixed $value 值(不为空则设置)
     * @return mixed
     */
    final static public function params(int|string|array $params,mixed $value=null): mixed {
        if(is_array($params)||$value!==null) {
            self::set($params,$value);
            return null;
        }
        return self::param($params);
    }

    /**
     * 获取参数
     * 
     * @access public
     * @param int|string $params 参数
     * @param mixed $default 默认值
     * @return mixed
     */
    final static public function param(int|string $params,mixed $default=null): mixed {
        return self::$request_params[$params]??$default;
    }

    /**
     * 设置参数
     * 
     * @access public
     * @param int|string|array $params 参数
     * @param mixed $value 值
     * @return void
     */
    final static public function set(int|string|array $params,mixed $value=null): void {
        if(is_array($params))
            self::$request_params=array_merge(self::$request_params,$params);
        else
            self::$request_params[$params]=$value;
    }

    /**
     * 返回全部Get参数
     * 
     * @access public
     * @return array
     */
    final static public function getAllGet(): array {
        return self::$request_params['_GET'];
    }

    /**
     * 返回全部Post参数
     * 
     * @access public
     * @return array
     */
    final static public function getAllPost(): array {
        return self::$request_params['_POST'];
    }

    /**
     * 返回全部Cookie参数
     * 
     * @access public
     * @return array
     */
    final static public function getAllCookie(): array {
        return self::$request_params['_COOKIE'];
    }

    /**
     * 返回全部请求参数
     * 
     * @access public
     * @return array
     */
    final static public function getAllParams(): array {
        return self::$request_params;
    }

    /**
     * 返回输入流
     * 
     * @access public
     * @return string
     */
    final static public function getInput(): string {
        return self::$request_params['_INPUT'];
    }

    /**
     * 获取POST参数
     * 
     * @access public
     * @param int|string $params 参数
     * @param mixed $default 默认值
     * @return mixed
     */
    final static public function getPost(int|string $params,mixed $default=null): mixed {
        return self::$request_params['_POST'][$params]??$default;
    }

    /**
     * 获取GET参数
     * 
     * @access public
     * @param int|string $params 参数
     * @param mixed $default 默认值
     * @return mixed
     */
    static public function getGet(int|string $params,mixed $default=null): mixed {
        return self::$request_params['_GET'][$params]??$default;
    }

    /**
     * 获取POST参数(-1则返回全部POST参数)
     * 
     * @access public
     * @param int|string $params 参数
     * @param mixed $default 默认值
     * @return mixed
     */
    final static public function post(int|string $params=-1,mixed $default=null): mixed {
        if($params===-1)
            return self::getAllPost();
        return self::getPost($params,$default);
    }

    /**
     * 获取GET参数(-1则返回全部GET参数)
     * 
     * @access public
     * @param int|string $params 参数
     * @param mixed $default 默认值
     * @return mixed
     */
    static public function get(int|string $params=-1,mixed $default=null): mixed {
        if($params===-1)
            return self::getAllGet();
        return self::getGet($params,$default);
    }

    /**
     * 获取COOKIE参数
     * 
     * @access public
     * @param int|string $params 参数
     * @param mixed $default 默认值
     * @return mixed
     */
    static public function getCookie(int|string $params,mixed $default=null): mixed {
        return self::$request_params['_COOKIE'][Config::get('cookie.prefix','').$params]??$default;
    }

    /**
     * 获取COOKIE参数(-1则返回全部COOKIE参数)
     * 
     * @access public
     * @param int|string $params 参数
     * @param mixed $default 默认值
     * @return mixed
     */
    static public function cookie(int|string $params=-1,mixed $default=null): mixed {
        if($params===-1)
            return self::getAllCookie();
        return self::getCookie($params,$default);
    }

    /**
     * 返回所有请求参数的键值
     * 
     * @access public
     * @param string $type 参数类型(all|get|post|cookie)
     * @return array
     */
    final static public function keys(string $type='all'): array {
        $type=strtolower($type);
        if($type=='all')
            return array_keys(self::$request_params);
        else if($type=='get')
            return array_keys(self::$request_params['_GET']);
        else if($type=='post')
            return array_keys(self::$request_params['_POST']);
        else if($type=='cookie')
            return array_keys(self::$request_params['_COOKIE']);
        else
            return array();
    }

}