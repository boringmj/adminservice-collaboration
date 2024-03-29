<?php

namespace AdminService;

use base\Request as BaseRequest;

/**
 * Request核心类
 */
final class Request extends BaseRequest {

    /**
     * 文件信息缓存
     * 
     * @var array
     */
    static protected array $file_info=array();

    /**
     * 获取上传的文件信息(仅返回error为0的文件信息)
     * 
     * @access public
     * @param string $name 字段名
     * @param bool $is_cache 是否使用缓存
     * @return array
     */
    static public function getUploadFile(string $name,bool $is_cache=true): array {
        // 判断是否使用缓存
        if($is_cache&&isset(self::$file_info[$name]))
            return self::$file_info[$name];
        // 判断该字段是否存在
        if(!isset($_FILES[$name]))
            return array();
        $temp=$_FILES[$name];
        // 如果上传的文件是单个文件,则将其转换为数组
        if(!is_array($temp['name'])) {
            // 判断是否为上传错误
            if($temp['error']!=0)
                return array();
            $temp=array(
                'name'=>array($temp['name']),
                'type'=>array($temp['type']),
                'tmp_name'=>array($temp['tmp_name']),
                'error'=>array($temp['error']),
                'size'=>array($temp['size'])
            );
        }
        // 处理上传的文件
        $file_list=array();
        $file_count=count($temp['name']);
        for($i=0;$i<$file_count;$i++) {
            // 判断是否为上传错误
            if($temp['error'][$i]!=0)
                continue;
            $file=array(
                'name'=>$temp['name'][$i],
                'type'=>$temp['type'][$i],
                'tmp_name'=>$temp['tmp_name'][$i],
                'error'=>$temp['error'][$i],
                'size'=>$temp['size'][$i],
                'md5'=>md5_file($temp['tmp_name'][$i]),
                'sha1'=>sha1_file($temp['tmp_name'][$i]),
                'ext'=>pathinfo($temp['name'][$i],PATHINFO_EXTENSION)
            );
            $file_list[]=$file;
        }
        // 缓存文件信息
        self::$file_info[$name]=$file_list;
        return $file_list;
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
            self::setGet($params,$value,$enforce);
            return null;
        }
        return self::getGet($params);
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
            self::setPost($params,$value,$enforce);
            return null;
        }
        return self::getPost($params);
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
            self::setCookie($params,$value,$enforce);
            return null;
        }
        return self::getCookie(Config::get('cookie.prefix','').$params);
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
     * @param int|null $expire 过期时间
     * @param string|null $path 路径
     * @param string|null $domain 域名
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
     * 结束运行
     *
     * @access public
     * @param mixed $data 数据
     * @return void
     * @throws Exception
     */
    static public function requestExit(mixed $data=null): void {
        // 加载Header
        $config_header=Config::get('request.'.self::$request_info['return_type'].'.header',array());
        // 合并Header,如果冲突保留程序中设置的值
        self::$request_info['return_header']=array_merge($config_header,self::$request_info['return_header']);
        foreach(self::$request_info['return_header']??array() as $name=>$value)
            self::setHeader($name,$value);
        // 设置Cookie
        if(!empty(self::$cookie)) {
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
                ),Config::get('request.default.json.flag',0));
            else
                self::$request_info['return_data']=json_encode(array(
                    'code'=>self::$request_info['code'],
                    'msg'=>self::$request_info['msg'],
                    'data'=>$data
                ),Config::get('request.default.json.flag',0));
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
     * 设置返回类型
     * 
     * @access public
     * @param string $type 数据类型(*,default:html)
     * @return void
     */
    final static public function setReturnType(string $type): void {
        self::$request_info['return_type']=$type;
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