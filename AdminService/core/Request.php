<?php

namespace AdminService;

use AdminService\Config;
use base\Request as BaseRequest;

/**
 * Request核心类
 */
final class Request extends BaseRequest {

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
            return self::setGet($params,$value,$enforce);
        }
        return self::getGet($params);
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
            return self::setPost($params,$value,$enforce);
        }
        return self::getPost($params);
    }

    /**
     * 获取POST参数
     * 
     * @access public
     * @param int|string $params 参数
     * @param mixed $default 默认值
     * @return mixed
     */
    static public function getPost(int|string $params,mixed $default=null): mixed {
        return self::$request_params['_POST'][$params]??$default;
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
     * 设置或获取COOKIE请求参数(设置Cookie时Cookie将会在本次以及后续请求中生效)
     * 
     * @access public
     * @param int|string|array $params 参数
     * @param mixed $value 值(不为空则设置)
     * @param bool $enforce 是否与 params() 方法同步
     * @return mixed
     */
    static public function cookieParams(int|string|array $params,mixed $value=null,bool $enforce=false): mixed {
        if(is_array($params)||$value!==null) {
            return self::setCookie($params,$value,$enforce);
        }
        return self::getCookie(Config::get('cookie.prefix','').$params);
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
     * 设置COOKIE参数(设置Cookie时Cookie将会在本次以及后续请求中生效)
     * 
     * @access public
     * @param int|string|array $params 参数
     * @param mixed $value 值(当 $params 为数组时此参数无效)
     * @param bool $enforce 是否与 params() 方法同步
     * @return void
     */
    static public function setCookie(int|string|array $params,mixed $value=null,bool $enforce=false): void {
        if(is_array($params)) {
            foreach($params as $key=>$val)
                self::$request_info['cookie'][$key]=$val;
            self::$request_params['_COOKIE']=array_merge(self::$request_params['_COOKIE'],$params);
        } else {
            self::$request_info['cookie'][$params]=$value;
            self::$request_params['_COOKIE'][$params]=$value;
        }
        // 强制通过 params() 方法设置一次参数
        if($enforce)
            self::params($params,$value);
    }

    /**
     * 添加返回的Cookie信息
     * 
     * @access public
     * @param string|array $params 参数(string时为cookie名,array时为cookie数组)
     * @param string $value Cookie值($params 参数为数组时此参数无效)
     * @param int $expire 过期时间
     * @param string $path 路径
     * @param string $domain 域名
     * @return void
     */
    static public function addCookie(string|array $params,string $value,?int $expire=null,?string $path=null,?string $domain=null): void {
        if(is_array($params)) {
            foreach($params as $key=>$val)
                self::$request_info['cookie'][$key]=array(
                    'value'=>$val,
                    'expire'=>$expire,
                    'path'=>$path,
                    'domain'=>$domain
                );
        } else {
            self::$request_info['cookie'][$params]=array(
                'value'=>$value,
                'expire'=>$expire,
                'path'=>$path,
                'domain'=>$domain
            );
        }
    }

    /**
     * 返回所有请求参数的键值
     * 
     * @access public
     * @param string $type 参数类型(all|get|post|cookie)
     * @return array
     */
    final static public function keys($type='all'): array {
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
     * 结束运行
     * 
     * @access public
     * @param mixed $data 数据
     * @return void
     */
    static public function requestExit(mixed $data=null): void {
        // 加载Header
        foreach(self::$request_info['return_header']??array() as $name=>$value)
            self::setHeader($name,$value);
        // 设置Cookie
        if(!empty(self::$cookie))
        {
            // 判断对象是否存在setByArray方法
            if(method_exists(self::$cookie,'setByArray'))
                self::$cookie->setByArray(self::$request_info['cookie']);
            else
                throw new Exception('Cookie object must have setByArray() method',100201);
        }
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
        } else if((self::$request_info['return_type']??null)=='html') {
            if(is_string($data)||is_null($data))
                self::$request_info['return_data']=$data;
            else
                throw new Exception('Return data type is not string|null!',100202,array(
                    'data'=>$data
                ));
        } else {
            if(is_string($data)||is_null($data))
                self::$request_info['return_data']=$data;
        }
        exit();
    }

    /**
     * 结束时输出内容
     * 
     * @access public
     * @return void
     */
    static public function requestEcho(): void {
        echo self::$request_info['return_data']??null;
    }

    /**
     * 设置返回类型(需要注意,每次设置都会引入对应的Header,如果已经设置过Header,则会覆盖)
     * 
     * @access public
     * @param string $type 数据类型(*,default:html)
     * @return void
     */
    final static public function setReturnType(string $type): void {
        self::$request_info['return_type']=$type;
        $header=Config::get('request.'.$type.'.header',array());
        // 合并Header,如果冲突保留后面数组的值
        self::$request_info['return_header']=array_merge(self::$request_info['return_header'],$header);
    }

    /**
     * 设置Header
     * 
     * @access public
     * @param string $name 名称
     * @param string $value 值
     * @return void
     */
    final static public function setHeader(string $name,string $value): void {
        header($name.': '.$value);
    }

}

?>