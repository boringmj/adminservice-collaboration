<?php

namespace base;

use base\Cookie;
use AdminService\Config;
use AdminService\App;

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
     * @var \base\Cookie
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
     * @return void
     */
    abstract static public function setHeader(string $name,string $value): void;

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
     * 初始化请求
     * 
     * @access public
     * @param Cookie $cookie Cookie对象
     * @return void
     */
    final static public function init(?Cookie $cookie=null): void {
        if($cookie===null)
            $cookie=App::get('Cookie');
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
        if(self::$request_info['return_type']==='json')
            self::$request_info['return_header']=Config::get('request.json.header');
        else
            self::$request_info['return_header']=Config::get('request.html.header');
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
            '_COOKIE'=>$_COOKIE
        );
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
            return self::set($params,$value);
        }
        return self::get($params);
    }

    /**
     * 获取参数
     * 
     * @access public
     * @param int|string $params 参数
     * @param mixed $default 默认值
     * @return mixed
     */
    final static public function get(int|string $params,mixed $default=null): mixed {
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

}

?>