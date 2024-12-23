<?php

namespace AdminService\common;

use AdminService\App;

/**
 * http请求工具类
 *
 * Class HttpHelper
 */
class HttpHelper {

    /**
     * 请求参数
     * @var array
     */
    protected array $request=array();

    /**
     * 响应参数
     * @var array
     */
    protected array $response=array(
        'status_code'=>0,
        'headers'=>array(),
        'body'=>'',
        'error'=>null
    );

    /**
     * HttpHelper constructor.
     *
     * @access public
     * @param string|null $url 请求地址
     * @param string|null $method 请求方法
     * @param array|null $headers 请求头
     * @param string|null $body 请求体
     * @param int $timeout 超时时间
     */
    public function __construct(
        ?string $url=null,
        ?string $method=null,
        ?array $headers=null,
        ?string $body=null,
        int $timeout=30
    ) {
        $this->setUrl($url??'');
        $this->setMethod($method??'GET');
        $this->setHeaders($headers??array());
        $this->setBody($body??'');
        $this->setTimeout($timeout);
        $this->request['disable_ssl_verify']=false;
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
    public function setUrl(string $url): self {
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
    public function setMethod(string $method): self {
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
    public function setHeaders(array $headers): self {
        foreach($headers as $key=>$value)
            if(is_numeric($key))
                $this->request['headers'][]=$value;
            else
                $this->request['headers'][]="{$key}: {$value}";
        return $this;
    }

    /**
     * 设置请求体
     * 
     * @access public
     * @param string $body 请求体
     * @return self
     */
    public function setBody(string $body): self {
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
    public function setTimeout(int $timeout): self {
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
    public function setStream(callable $callback): self {
        $this->response['stream']['open']=true;
        $this->response['stream']['callback']=$callback;
        return $this;
    }

    /**
     * 禁用ssl验证
     * 
     * @access public
     * @param bool $disable 是否禁用
     * @return self
     */
    public function disableSslVerify(bool $disable=true): self {
        $this->request['disable_ssl_verify']=$disable;
        return $this;
    }

    /**
     * 执行请求
     * 
     * @access public
     * @return self
     */
    public function execute(): self {
        $ch=curl_init();
        curl_setopt($ch,CURLOPT_URL,$this->request['url']);
        // 检查是否需要开启ssl
        if($this->request['disable_ssl_verify']??false) {
            curl_setopt($ch,CURLOPT_SSL_VERIFYPEER,false);
            curl_setopt($ch,CURLOPT_SSL_VERIFYHOST,false);
        }
        $method=strtoupper($this->request['method']??'GET');
        curl_setopt($ch,CURLOPT_CUSTOMREQUEST,$method);
        curl_setopt($ch,CURLOPT_HTTPHEADER,$this->request['headers']??array());
        if($method=='POST')
            curl_setopt($ch,CURLOPT_POSTFIELDS,$this->request['body']??'');
        curl_setopt($ch,CURLOPT_RETURNTRANSFER,true);
        curl_setopt($ch,CURLOPT_TIMEOUT,$this->request['timeout']??30);
        // 判断是否为流式请求
        if($this->response['stream']['open']??false) {
            $callback=$this->response['stream']['callback'];
            curl_setopt($ch,CURLOPT_WRITEFUNCTION,function($ch,$data) use ($callback) {
                // 执行回调函数
                call_user_func($callback,$data);
                return strlen($data);
            });
        }
        $response=curl_exec($ch);
        if($response===false)
            $this->response['error']=curl_error($ch);
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
    public function getBody(): string {
        return $this->response['body'];
    }

    /**
     * 获取响应状态码
     * 
     * @access public
     * @return int
     */
    public function getStatusCode(): int {
        return $this->response['status_code'];
    }

    /**
     * 获取响应头
     * 
     * @access public
     * @return array
     */
    public function getHeaders(): array {
        return $this->response['headers'];
    }

    /**
     * 获取响应头中的某个值
     * 
     * @access public
     * @param string $key 响应头key
     * @return string
     */
    public function getHeader(string $key): string {
        return $this->response['headers'][$key]??'';
    }

    /**
     * 获取响应错误
     * 
     * @access public
     * @return string|null
     */
    public function getError(): ?string {
        return $this->response['error'];
    }

    /**
     * 快速发起get请求
     * 
     * @access public
     * @param string $url 请求地址
     * @param array $headers 请求头
     * @param int $timeout 超时时间
     * @param callable|null $error_callback 错误回调(支持的参数:code,body,headers,error)
     * @param bool $disable_ssl_verify 是否禁用ssl验证
     * @return string
     */
    public static function get(
        string $url,
        array $headers=array(),
        int $timeout=30,
        ?callable $error_callback=null,
        bool $disable_ssl_verify=false
    ): string {
        $response=App::get(
            HttpHelper::class,$url,'GET',$headers,null,$timeout
        )->disableSslVerify($disable_ssl_verify)->execute();
        if($response->getStatusCode()!=200&&$error_callback!=null) {
            App::exec_function($error_callback,array(
                'code'=>$response->getStatusCode(),
                'body'=>$response->getBody(),
                'headers'=>$response->getHeaders(),
                'error'=>$response->getError()
            ));
        }
        return $response->getBody();
    }

    /**
     * 快速发起post请求
     * 
     * @access public
     * @param string $url 请求地址
     * @param string|array $data 请求体(传入数组则会自动转为json字符串)
     * @param array $headers 请求头
     * @param int $timeout 超时时间
     * @param callable|null $error_callback 错误回调(支持的参数:code,body,headers,error)
     * @param bool $disable_ssl_verify 是否禁用ssl验证
     * @return string
     */
    public static function post(
        string $url,
        string|array $data,
        array $headers=array(),
        int $timeout=30,
        ?callable $error_callback=null,
        bool $disable_ssl_verify=false
    ): string {
        if(is_array($data))
            $data=json_encode($data);
        $response=App::get(
            HttpHelper::class,$url,'POST',$headers,$data,$timeout
        )->disableSslVerify($disable_ssl_verify)->execute();
        if($response->getStatusCode()!=200&&$error_callback!=null) {
            App::exec_function($error_callback,array(
                'code'=>$response->getStatusCode(),
                'body'=>$response->getBody(),
                'headers'=>$response->getHeaders(),
                'error'=>$response->getError()
            ));
        }
        return $response->getBody();
    }

}