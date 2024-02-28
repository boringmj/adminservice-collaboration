<?php

namespace AdminService\common;

/**
 * http请求工具类
 *
 * Class HttpHelper
 */
class HttpHelper {

    protected $request=array();
    protected $response=array(
        'status_code'=>0,
        'headers'=>array(),
        'body'=>''
    );

    /**
     * HttpHelper constructor.
     * 
     * @access public
     * @param string $url 请求地址
     * @param string $method 请求方法
     * @param array $headers 请求头
     * @param string $body 请求体
     * @param int $timeout 超时时间
     * @return void
     */
    public function __construct(
        ?string $url=null,
        ?string $method=null,
        ?array $headers=null,
        ?string $body=null,
        int $timeout=30
    ){
        $this->request['url']=$url??'';
        $this->request['method']=$method??'';
        $this->request['headers']=$headers??array();
        $this->request['body']=$body??'';
        $this->request['timeout']=$timeout;
        $this->response['stream']=array(
            'open'=>false,
            'callback'=>null
        );
    }

    /**
     * 设置请求地址
     * 
     * @access public
     * @param string $url 请求地址
     * @return self
     */
    public function setUrl(string $url){
        $this->request['url']=$url;
        return $this;
    }

    /**
     * 设置请求方法
     * 
     * @access public
     * @param string $method 请求方法
     * @return self
     */
    public function setMethod(string $method){
        $this->request['method']=$method;
        return $this;
    }

    /**
     * 设置请求头
     * 
     * @access public
     * @param array $headers 请求头
     * @return self
     */
    public function setHeaders(array $headers){
        $this->request['headers']=$headers;
        return $this;
    }

    /**
     * 设置请求体
     * 
     * @access public
     * @param string $body 请求体
     * @return self
     */
    public function setBody(string $body){
        $this->request['body']=$body;
        return $this;
    }

    /**
     * 设置超时时间
     * 
     * @access public
     * @param int $timeout 超时时间
     * @return self
     */
    public function setTimeout(int $timeout){
        $this->request['timeout']=$timeout;
        return $this;
    }

    /**
     * 设置流式请求(仅生效一次,请求完成后会自动关闭)
     * 
     * @access public
     * @param callable $callback 回调函数
     * @return self
     */
    public function setStream(callable $callback){
        $this->response['stream']['open']=true;
        $this->response['stream']['callback']=$callback;
        return $this;
    }

    /**
     * 执行请求
     * 
     * @access public
     * @return self
     */
    public function execute(){
        $ch = curl_init();
        curl_setopt($ch,CURLOPT_URL,$this->request['url']);
        $method=strtoupper($this->request['method']);
        curl_setopt($ch,CURLOPT_CUSTOMREQUEST,$method);
        curl_setopt($ch,CURLOPT_HTTPHEADER,$this->request['headers']);
        if($method=='POST')
            curl_setopt($ch,CURLOPT_POSTFIELDS,$this->request['body']);
        curl_setopt($ch,CURLOPT_RETURNTRANSFER,true);
        curl_setopt($ch,CURLOPT_TIMEOUT,$this->request['timeout']);
        // 判断是否为流式请求
        if($this->response['stream']['open']) {
            $callback = $this->response['stream']['callback'];
            curl_setopt($ch,CURLOPT_WRITEFUNCTION,function($ch,$data) use ($callback){
                // 执行回调函数
                call_user_func($callback,$data);
                return strlen($data);
            });
        }
        $response = curl_exec($ch);
        $this->response['status_code']=curl_getinfo($ch,CURLINFO_HTTP_CODE);
        $this->response['headers']=curl_getinfo($ch);
        $this->response['body']=$response;
        curl_close($ch);
        return $this;
    }

    /**
     * 获取响应体
     * 
     * @access public
     * @return string
     */
    public function getBody(){
        return $this->response['body'];
    }

    /**
     * 获取响应状态码
     * 
     * @access public
     * @return int
     */
    public function getStatusCode(){
        return $this->response['status_code'];
    }

    /**
     * 获取响应头
     * 
     * @access public
     * @return array
     */
    public function getHeaders(){
        return $this->response['headers'];
    }

    /**
     * 获取响应头中的某个值
     * 
     * @access public
     * @param string $key 响应头key
     * @return string
     */
    public function getHeader(string $key){
        return $this->response['headers'][$key]??'';
    }

}

?>