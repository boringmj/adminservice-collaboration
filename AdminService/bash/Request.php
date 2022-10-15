<?php

namespace bash;

use AdminService\Exception;
use AdminService\Config;

class Request {

    /**
     * 请求参数
     */
    static private array $request_params;

    /**
     * 返回数据的信息
     */
    static private array $request_info;

    /**
     * 构造方法
     * 
     * @access public
     */
    final public function __construct() {
        $this->init();
    }

    /**
     * 初始化请求参数
     * 
     * @access public
     * @return void
     */
    final static public function init(): void {
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
        // 设置默认返回数据信息
        self::$request_info=array(
            'return_type'=>Config::get('request.default.type','html'),
            'return_header'=>array(),
            'code'=>Config::get('request.default.json.code',1),
            'msg'=>Config::get('request.default.json.msg','success'),
            'data'=>array(),
            'return_data'=>null
        );
        if(self::$request_info['return_type']==='json')
            self::$request_info['return_header']=Config::get('request.json.header');
        else
            self::$request_info['return_header']=Config::get('request.html.header');
    }

    /**
     * 结束运行
     * 
     * @access public
     * @param mixed $data 数据
     * @return void
     */
    final static public function requestExit(mixed $data=null): void {
        // 加载Header
        foreach(self::$request_info['return_header']??array() as $name=>$value)
            self::setHeader($name,$value);
        //根据返回类型返回数据
        if((self::$request_info['return_type']??null)=='json') {
            if(is_array($data))
                self::$request_info['return_data']=json_encode(array(
                    'code'=>$data['code']??self::$request_info['code'],
                    'msg'=>$data['msg']??self::$request_info['msg'],
                    'data'=>$data['data']??self::$request_info['data']
                ));
            else
                self::$request_info['return_data']=json_encode(array(
                    'code'=>self::$request_info['code'],
                    'msg'=>self::$request_info['msg'],
                    'data'=>$data
                ));
        }
        else
            if(is_string($data)||is_null($data))
                self::$request_info['return_data']=$data;
            else
                throw new Exception('Return data type is not string|null!',100202,array(
                    'data'=>$data
                ));
        exit();
    }

    /**
     * 结束时输出内容
     */
    final static public function requestEcho(): void {
        echo self::$request_info['return_data']??null;
    }

    /**
     * 获取或设置请求参数(传入数组则设置请求参数)
     * 
     * @access public
     * @param int|string|array $params 参数
     * @param mixed $value 值(不为空则设置)
     * @return mixed
     */
    static public function params(int|string|array $params,mixed $value=null): mixed {
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
     * @return mixed
     */
    static public function get(int|string $params): mixed {
        return self::$request_params[$params]??null;
    }

    /**
     * 设置参数
     * 
     * @access public
     * @param int|string|array $params 参数
     * @param mixed $value 值
     * @return void
     */
    static public function set(int|string|array $params,mixed $value=null): void {
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
    static public function getParams(int|string|array $params,mixed $value=null,bool $enforce=false): mixed {
        if(is_array($params)||$value!==null) {
            if($enforce)
                self::params($params,$value);
            return self::setGet($params,$value);
        }
        return self::getGet($params);
    }

    /**
     * 获取GET参数
     * 
     * @access public
     * @param int|string $params 参数
     * @return mixed
     */
    static public function getGet(int|string $params): mixed {
        return self::$request_params['_GET'][$params]??null;
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
    static public function setGet(int|string|array $params,mixed $value=null,bool $enforce=false): void {
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
    static public function postParams(int|string|array $params,mixed $value=null,bool $enforce=false): mixed {
        if(is_array($params)||$value!==null) {
            if($enforce)
                self::params($params,$value);
            return self::setPost($params,$value);
        }
        return self::getPost($params);
    }

    /**
     * 获取POST参数
     * 
     * @access public
     * @param int|string $params 参数
     * @return mixed
     */
    static public function getPost(int|string $params): mixed {
        return self::$request_params['_POST'][$params]??null;
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
    static public function setPost(int|string|array $params,mixed $value=null,bool $enforce=false): void {
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
     * @param string $type 参数类型(all|get|post|cookie)
     * @return array
     */
    static public function keys($type='all'): array {
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

    /**
     * 设置Header
     * 
     * @access public
     * @param string $name 名称
     * @param string $value 值
     * @return void
     */
    static public function setHeader(string $name,string $value): void {
        header($name.': '.$value);
    }

    /**
     * 设置返回类型
     * 
     * @access public
     * @param string $type 数据类型(html|json,default:html)
     * @return void
     */
    static public function setReturnType(string $type): void {
        if($type==='json')
        {
            self::$request_info['return_type']='json';
            $header=Config::get('request.json.header');
        }
        else
        {
            self::$request_info['return_type']='html';
            $header=Config::get('request.html.header');
        }
        // 合并Header,如果冲突保留后面数组的值
        self::$request_info['return_header']=array_merge(self::$request_info['return_header'],$header);
    }
}

?>