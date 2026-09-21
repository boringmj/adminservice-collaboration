<?php

namespace AdminService;

use base\Cookie;
use base\Request;
use base\Response as BaseResponse;
use AdminService\ResponseProcessor\Http;

use function array_keys;
use function explode;
use function headers_sent;
use function http_response_code;
use function in_array;
use function is_array;
use function is_string;
use function str_starts_with;
use function strtolower;
use function substr;
use function trim;

/**
 * Response核心类
 *
 * - 每个请求一个实例: 状态码 / Header / Cookie / 返回内容均随实例走
 */
final class Response extends BaseResponse {

    /**
     * 请求头信息
     * @var Data
     */
    protected Data $headers;

    /**
     * Cookie信息
     * @var Data
     */
    protected Data $cookies;

    /**
     * 构造方法
     *
     * @access public
     */
    public function __construct() {
        $this->headers=new Data();
        $this->cookies=new Data();
    }

    /**
     * 获取一个标准的返回类型
     *
     * @access public
     * @param string|null $type 类型
     * @return string
     */
    public function getStandardContentType(
        ?string $type=null
    ): string {
        $type=$type??$this->contentType;
        $type_list=array_keys(Config::get('response.default.type',[]));
        // 判断当前类型是否存在
        if(in_array($type,$type_list))
            return $type;
        return $type_list[0];
    }

    /**
     * 获取Header
     *
     * @access public
     * @param string $name Header名
     * @return string
     */
    public function getHeader(string $name): string {
        return (string)$this->headers->get($name,'');
    }

    /**
     * 设置Header(array类型仅支持name=>value)
     *
     * @access public
     * @param string|array $params 参数(string时为header名,array时为header数组)
     * @param string $value $params 参数为数组时此参数无效)
     * @return void
     */
    public function setHeader(
        string|array $params,
        string $value=''
    ): void {
        if(is_string($params)) {
            $this->headers->set($params,$value);
            return;
        }
        foreach($params as $key=>$val) {
            $this->headers->set($key,$val);
        }
    }

    /**
     * 获取Cookie值
     *
     * - 取的是待下发Cookie的值(带属性的写法存为数组, 此处取其中的value)
     *
     * @access public
     * @param string $name Cookie名
     * @return string
     */
    public function getCookie(string $name): string {
        $cookie=$this->cookies->get($name,'');
        if(is_array($cookie))
            return (string)($cookie['value']??'');
        return (string)$cookie;
    }

    /**
     * 设置Cookie信息(array类型的值支持name=>value或者name=>array(expire...))
     *
     * @access public
     * @param string|array $params 参数(string时为cookie名,array时为cookie数组)
     * @param string|null $value Cookie值($params 参数为数组时此参数无效)
     * @param int|null $expire 过期时间($params 参数为数组时此参数无效)
     * @param string|null $path 路径($params 参数为数组时此参数无效)
     * @param string|null $domain 域名($params 参数为数组时此参数无效)
     * @param bool $secure 是否安全传输($params 参数为数组时此参数无效)
     * @param bool $httponly 是否仅http传输($params 参数为数组时此参数无效)
     * @return void
     */
    public function setCookie(
        string|array $params,
        ?string $value=null,
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
                $this->cookies->set($val['name'],[
                    'value'=>$val['value'],
                    'expire'=>$val['expire']??null,
                    'path'=>$val['path']??null,
                    'domain'=>$val['domain']??null,
                    'secure'=>$val['secure']??null,
                    'httponly'=>$val['httponly']??null
                ]);
            } else {
                $this->cookies->set($key,$val);
            }
        }
    }

    /**
     * 渲染结果
     *
     * @access public
     * @param Request $request 请求对象
     * @return string
     */
    public function render(Request $request): string {
        if($this->return_content!==null) return $this->return_content;
        $type=$this->getStandardContentType();
        if($type=='*/*') {
            // 获取 Accept 头信息
            $accept_headers=explode(',',(string)$request->getHeader('accept'));
            // 通过递归寻找匹配的类型
            $type=$this->findAcceptType($accept_headers);
        }
        $config=Config::get('response.default.type.'.$type,[]);
        $class=$config['class']??Http::class;
        // 处理器需要写入本实例: 显式传入, 不依赖容器中的同名单例
        App::new($class,response:$this,config:$config)->handle();
        // 合并header
        $headers=$config['headers']??[];
        $this->headers->batchSet($headers);
        return $this->return_content??'';
    }

    /**
     * 寻找匹配的类型
     *
     * @access private
     * @param array<string,mixed> $accept_headers Accept头信息
     * @return string
     */
    private function findAcceptType(array $accept_headers): string {
        $allow_types=array_keys(Config::get('response.default.type',[]));
        $matches=[];
        foreach($accept_headers as $header) {
            // 拆分类型和权重
            $parts=explode(';',trim($header));
            $type=strtolower(trim($parts[0]));
            $q=1.0; // 默认权重
            if(isset($parts[1])&&str_starts_with(trim($parts[1]),'q='))
                $q=(float) substr(trim($parts[1]),2);
            // 匹配允许的类型或通配符
            if(in_array($type,$allow_types)||$type==='*/*')
                $matches[$type]=$q;
        }
        // 按权重排序,权重高的优先
        if(!empty($matches)) {
            arsort($matches,SORT_NUMERIC);
            foreach($matches as $type=>$q)
                if($type!=='*/*') return $type;
        }
        // 如果都不匹配或只匹配通配符，返回默认类型
        return $allow_types[0]??'*/*';
    }

    /**
     * 发送请求头和状态码
     *
     * @access public
     * @return void
     */
    public function sendHeaders(): void {
        // 判断是否还可以返回请求头
        if(!headers_sent()) {
            http_response_code($this->getStatusCode());
            foreach($this->headers as $key=>$val)
                header($key.': '.$val);
            $cookie=App::get(Cookie::class);
            $cookie->setByArray($this->cookies->all());
        }
    }

    /**
     * 结束响应并发送数据
     *
     * @access public
     * @param Request $request 请求对象
     * @return void
     */
    public function send(Request $request): void {
        $temp=$this->render($request);
        // 发送请求头
        $this->sendHeaders();
        // HEAD 只发送头部, 不输出响应体(HTTP 规范要求)
        if($request->getServer('REQUEST_METHOD')==='HEAD')
            return;
        // 渲染结果
        echo $temp;
    }

}
