<?php

namespace bash;

class Request {

    /**
     * 结束前的数据
     */
    static private $data_exit;

    /**
     * 请求参数
     */
    static public $request_params;

    /**
     * 构造方法
     * 
     * @access public
     * @return Request
     */
    final public function __construct() {
        $this->init();
        return $this;
    }

    /**
     * 初始化请求参数
     * 
     * @access public
     * @return void
     */
    final static public function init() {
        // 初始化请求参数
        self::$request_params=array(
            '_GET'=>$_GET,
            '_POST'=>$_POST,
            '_COOKIE'=>$_COOKIE
        );
        $_GET=array();
        $_POST=array();
        $_COOKIE=array();
        // 按GPC顺序初始化请求参数
        self::$request_params=array_merge(
            self::$request_params['_GET'],
            self::$request_params['_POST'],
            self::$request_params['_COOKIE'],
            self::$request_params
        );
    }

    /**
     * 结束运行
     * 
     * @access public
     * @param string $message
     * @return void
     */
    final static public function requestExit(string $message='') {
        self::$data_exit=array(
            'message'=>$message
        );
        exit();
    }

    /**
     * 结束时输出内容
     */
    final static public function requestEcho() {
        echo self::$data_exit['message'];
    }

    /**
     * 获取或设置请求参数(传入数组则设置请求参数)
     * 
     * @access public
     * @param int|string|array $params 参数
     * @param mixed $value 值(不为空则设置)
     * @return mixed
     */
    static public function params(int|string|array $params,mixed $value='')
    {
        if(is_array($params))
            self::$request_params=array_merge(self::$request_params,$params);
        else if(empty($value))
            return self::$request_params[$params]??'';
        else
            self::$request_params[$params]=$value;
    }

    /**
     * 获取参数
     * 
     * @access public
     * @param int|string $params 参数
     * @return mixed
     */
    static public function get(int|string $params)
    {
        return self::$request_params[$params]??'';
    }

    /**
     * 设置参数
     * 
     * @access public
     * @param int|string|array $params 参数
     * @param mixed $value 值
     * @return void
     */
    static public function set(int|string|array $params, mixed $value='')
    {
        if(is_array($params))
            self::$request_params=array_merge(self::$request_params,$params);
        else
            self::$request_params[$params]=$value;
    }

    /**
     * 设置或获取GET请求参数
     * 
     * @access public
     * @param int|string|array $params 参数
     * @param mixed $value 值(不为空则设置)
     * @param bool $enforce 是否与 params() 方法同步
     * @return mixed
     */
    static public function getParams(int|string|array $params,mixed $value='',bool $enforce=false)
    {
        if(is_array($params))
            self::$request_params['_GET']=array_merge(self::$request_params['_GET'],$params);
        else if(empty($value))
            return self::$request_params['_GET'][$params]??'';
        else
            self::$request_params['_GET'][$params]=$value;
        // 强制通过 params() 方法设置一次参数
        if($enforce)
            self::params($params,$value);
    }

    /**
     * 获取GET参数
     * 
     * @access public
     * @param int|string $params 参数
     * @return mixed
     */
    static public function getGet(int|string $params)
    {
        return self::$request_params['_GET'][$params]??'';
    }

    /**
     * 设置GET参数
     * 
     * @access public
     * @param int|string|array $params 参数
     * @param mixed $value 值(当 $params 为数组时此参数无效)
     * @param bool $enforce 是否与 params() 方法同步
     * @return void
     */
    static public function setGet(int|string|array $params, mixed $value='',bool $enforce=false)
    {
        if(is_array($params))
            self::$request_params['_GET']=array_merge(self::$request_params['_GET'],$params);
        else
            self::$request_params['_GET'][$params]=$value;
        // 强制通过 params() 方法设置一次参数
        if($enforce)
            self::params($params,$value);
    }

    /**
     * 设置或获取POST请求参数
     * 
     * @access public
     * @param int|string|array $params 参数
     * @param mixed $value 值(不为空则设置)
     * @param bool $enforce 是否与 params() 方法同步
     * @return mixed
     */
    static public function postParams(int|string|array $params,mixed $value='',bool $enforce=false)
    {
        if(is_array($params))
            self::$request_params['_POST']=array_merge(self::$request_params['_POST'],$params);
        else if(empty($value))
            return self::$request_params['_POST'][$params]??'';
        else
            self::$request_params['_POST'][$params]=$value;
        // 强制通过 params() 方法设置一次参数
        if($enforce)
            self::params($params,$value);
    }

    /**
     * 获取POST参数
     * 
     * @access public
     * @param int|string $params 参数
     * @return mixed
     */
    static public function getPost(int|string $params)
    {
        return self::$request_params['_POST'][$params]??'';
    }

    /**
     * 设置POST参数
     * 
     * @access public
     * @param int|string|array $params 参数
     * @param mixed $value 值(当 $params 为数组时此参数无效)
     * @param bool $enforce 是否与 params() 方法同步
     * @return void
     */
    static public function setPost(int|string|array $params, mixed $value='',bool $enforce=false)
    {
        if(is_array($params))
            self::$request_params['_POST']=array_merge(self::$request_params['_POST'],$params);
        else
            self::$request_params['_POST'][$params]=$value;
        // 强制通过 params() 方法设置一次参数
        if($enforce)
            self::params($params,$value);
    }

    /**
     * 返回所有请求参数的键值
     * 
     * @access public
     * @param string $type 参数类型(all|get|post)
     * @return array
     */
    static public function keys($type='all')
    {
        if($type=='all')
            return array_keys(self::$request_params);
        else if($type=='get')
            return array_keys(self::$request_params['_GET']);
        else if($type=='post')
            return array_keys(self::$request_params['_POST']);
        else
            return array();
    }
}

?>