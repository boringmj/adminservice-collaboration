<?php

namespace AdminService;

use base\Cookie;
use base\Request;
use base\Response as BaseResponse;
use AdminService\ResponseProcessor\Http;

use function array_keys;
use function explode;
use function func_num_args;
use function headers_sent;
use function http_response_code;
use function in_array;
use function is_array;
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
     * 待发送Header
     * @var Data
     */
    protected Data $headers;

    /**
     * 待下发Cookie
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
     * @param string|null $type 类型(null 时取当前内容类型)
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
     * 获取或设置单个Header
     *
     * @access public
     * @param string $name Header名
     * @param string|null $value 值(null 时仅读取)
     * @return string
     */
    public function header(string $name,?string $value=null): string {
        if(func_num_args()>1)
            $this->headers->set($name,(string)$value);
        return (string)$this->headers->get($name,'');
    }

    /**
     * 批量设置Header
     *
     * @access public
     * @param array<string,string> $headers Header数组
     * @return void
     */
    public function headers(array $headers): void {
        foreach($headers as $name=>$value) {
            $this->headers->set($name,(string)$value);
        }
    }

    /**
     * 获取或设置单个Cookie
     *
     * @access public
     * @param string $name Cookie名
     * @param string|null $value Cookie值(null 时仅读取)
     * @param int|null $expire 过期时间
     * @param string|null $path 路径
     * @param string|null $domain 域名
     * @param bool $secure 是否安全传输
     * @param bool $httponly 是否仅http传输
     * @return string
     */
    public function cookie(
        string $name,
        ?string $value=null,
        ?int $expire=null,
        ?string $path=null,
        ?string $domain=null,
        ?bool $secure=null,
        ?bool $httponly=null
    ): string {
        if(func_num_args()>1) {
            $this->cookies->set($name,array(
                'value'=>(string)$value,
                'expire'=>$expire,
                'path'=>$path,
                'domain'=>$domain,
                'secure'=>$secure,
                'httponly'=>$httponly
            ));
        }
        $cookie=$this->cookies->get($name,'');
        if(is_array($cookie))
            return (string)($cookie['value']??'');
        return (string)$cookie;
    }

    /**
     * 批量设置Cookie
     *
     * @access public
     * @param array<string,mixed> $cookies Cookie数组
     * @return void
     */
    public function cookies(array $cookies): void {
        foreach($cookies as $name=>$value) {
            if(is_array($value))
                $this->cookie(
                    (string)($value['name']??$name),
                    $value['value']??'',
                    $value['expire']??null,
                    $value['path']??null,
                    $value['domain']??null,
                    $value['secure']??null,
                    $value['httponly']??null
                );
            else
                $this->cookie((string)$name,(string)$value);
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
            $accept_headers=explode(',',(string)$request->header('accept'));
            // 通过递归寻找匹配的类型
            $type=$this->findAcceptType($accept_headers);
        }
        $config=Config::get('response.default.type.'.$type,[]);
        $class=$config['class']??Http::class;
        // 处理器需要写入本实例: 显式传入, 不依赖容器中的同名单例
        App::new($class,response:$this,config:$config)->handle();
        // 合并header
        $headers=$config['headers']??[];
        $this->headers($headers);
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
            http_response_code($this->status());
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
        if($request->method()==='HEAD')
            return;
        // 渲染结果
        echo $temp;
    }

}
