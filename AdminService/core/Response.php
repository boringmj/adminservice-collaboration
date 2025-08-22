<?php

namespace AdminService;

use base\Cookie;
use base\Response as BaseResponse;
use AdminService\ResponseProcessor\Http;

/**
 * Response核心类
 */
final class Response extends BaseResponse {

    /**
     * 请求头信息
     * @var Data
     */
    static public Data $headers;

    /**
     * Cookie信息
     * @var Data
     */
    static public Data $cookies;

    /**
     * 获取一个标准的返回类型
     * 
     * @access public
     * @return string
     */
    static public function getStandardContentType(): string {
        $type_list=array_keys(Config::get('response.default.type',[]));
        // 判断当前类型是否存在
        if(in_array(self::$contentType,$type_list))
            return self::$contentType;
        return $type_list[0];
    }

    /**
     * 初始化
     * 
     * @access public
     * @return void
     */
    static public function init(): void {
        self::$headers=new Data();
        self::$cookies=new Data();
        self::setContentType('text/html');
    }

    /**
     * 获取Header
     * 
     * @access public
     * @param string $name Header名
     * @return string
     */
    static public function getHeader(string $name): string {
        return self::$headers->get($name);
    }

    /**
     * 设置Header(array类型仅支持name=>value)
     * 
     * @access public
     * @param string|array $params 参数(string时为header名,array时为header数组)
     * @param string $value $params 参数为数组时此参数无效)
     * @return void
     */
    static public function setHeader(
        string|array $params,
        string $value
    ): void {
        if(is_string($params)) {
            self::$headers->set($params,$value);
            return;
        }
        foreach($params as $key=>$val) {
            self::$headers->set($key,$val);
        }
    }

    /**
     * 获取Cookie
     * 
     * @access public
     * @param string $name Cookie名
     * @return string
     */
    static public function getCookie(string $name): string {
        return self::$cookies->get($name);
    }

    /**
     * 设置Cookie信息(array类型的值支持name=>value或者name=>array(expire...))
     *
     * @access public
     * @param string|array $params 参数(string时为cookie名,array时为cookie数组)
     * @param string $value Cookie值($params 参数为数组时此参数无效)
     * @param int|null $expire 过期时间($params 参数为数组时此参数无效)
     * @param string|null $path 路径($params 参数为数组时此参数无效)
     * @param string|null $domain 域名($params 参数为数组时此参数无效)
     * @param bool $secure 是否安全传输($params 参数为数组时此参数无效)
     * @param bool $httponly 是否仅http传输($params 参数为数组时此参数无效)
     * @return void
     */
    static public function setCookie(
        string|array $params,
        string $value,
        ?int $expire=null,
        ?string $path=null,
        ?string $domain=null,
        ?bool $secure=null,
        ?bool $httponly=null
        ): void {
        // 将string参数转换为数组
        if(is_string($params)) {
            $params=array(
                $params=>array(
                    'value'=>$value,
                    'expire'=>$expire,
                    'path'=>$path,
                    'domain'=>$domain,
                    'secure'=>$secure,
                    'httponly'=>$httponly
                )
            );
        }
        foreach($params as $key=>$val) {
            if(is_array($val)) {
                // 判断数组中是否存在name字段
                if(!isset($val['name'])) $val['name']=$key;
                self::$cookies->set($val['name'],[
                    'value'=>$val['value'],
                    'expire'=>$val['expire']??null,
                    'path'=>$val['path']??null,
                    'domain'=>$val['domain']??null,
                    'secure'=>$val['secure']??null,
                    'httponly'=>$val['httponly']??null
                ]);
            } else {
                self::$cookies->set($key,$val);
            }
        }
    }

    /**
     * 渲染结果
     * 
     * @access public
     * @return string
     */
    static public function render(): string {
        if(self::$return_content!==null) return self::$return_content;
        $type=self::getStandardContentType();
        $config=Config::get('response.default.type.'.$type);
        $class=$config['class']??Http::class;
        App::new($class,config:$config);
        // 合并header
        $headers=$config['headers']??[];
        self::$headers->batchSet($headers);
        return self::$return_content??'';
    }

    /**
     * 结束响应并发送数据
     * 
     * @access public
     * @return void
     */
    static public function send(): void {
        $temp=self::render();
        // 判断是否还可以返回请求头
        if(!headers_sent()) {
            http_response_code(self::getStatusCode());
            foreach(self::$headers as $key=>$val)
                header($key.': '.$val);
            $cookie=App::get(Cookie::class);
            $cookie->setByArray(self::$cookies->all());
        }
        // 渲染结果
        echo $temp;
    }

}