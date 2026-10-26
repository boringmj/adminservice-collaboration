<?php

namespace AdminService;

use AdminService\Config\Repository;
use base\ConfigInterface;

use base\Cookie;
use base\Request;
use base\Response as BaseResponse;
use AdminService\ResponseProcessor\Http;

use function array_keys;
use function array_map;
use function explode;
use function func_num_args;
use function header;
use function headers_sent;
use function http_response_code;
use function implode;
use function in_array;
use function is_array;
use function str_contains;
use function str_ends_with;
use function strtolower;
use function trim;

/**
 * Response核心类
 *
 * - 每个请求一个实例: 状态码 / Header / Cookie / 返回内容均随实例走
 * - `prepare()` 负责与请求相关的一步(内容协商、HEAD), `render()` / `send()` 不再依赖请求
 */
final class Response extends BaseResponse {

    /**
     * 配置(构造期可注入; 未注入时回落门面当前那份 —— 手工构造 / 测试用)
     * @var ConfigInterface|null
     */
    private ?ConfigInterface $config=null;

    /**
     * 取配置契约实例
     *
     * - 容器构建本对象时由构造参数注入(即 `Application::init()` 登记进容器的那一份)
     * - 手工 `new` 时没有注入 → **每次**回落门面当前那份(**不缓存**);
     *   注入过的那份则固定使用(不跟随门面切换; 实例本身仍可被修改, 不是值快照)
     *
     * @access private
     * @return ConfigInterface
     */
    private function config(): ConfigInterface {
        return $this->config??Config::repository()??new Repository(array());
    }

    /**
     * 待发送Header(值为字符串数组, 支持同名多值)
     * @var Data
     */
    protected Data $headers;

    /**
     * 待下发Cookie
     * @var Data
     */
    protected Data $cookies;

    /**
     * 是否只发送响应头(HEAD请求, 由 prepare() 标记)
     * @var bool
     */
    protected bool $head_only=false;

    /**
     * 构造方法
     *
     * @access public
     * @param ConfigInterface|null $config 配置契约实例(未注入时回落门面当前那份)
     */
    public function __construct(?ConfigInterface $config=null) {
        $this->config=$config;
        $this->headers=(new Data())->setCaseSensitive(false)->resetKey();
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
        $type=strtolower(trim(explode(';',$type??$this->contentType,2)[0]));
        $type_list=array_keys($this->config()->get('response.default.type',[]));
        // 判断当前类型是否存在
        if($type==='')
            $type='*/*';
        // 精确匹配(大小写与参数已归一)
        if(in_array($type,$type_list,true))
            return $type;
        // vendor JSON 回落到标准 JSON
        if(str_ends_with($type,'+json')&&in_array('application/json',$type_list,true))
            return 'application/json';
        // 未注册类型回落到默认桶
        return $type_list[0]??'*/*';
    }

    /**
     * 获取默认的具体内容类型(跳过通配桶)
     *
     * @access private
     * @return string
     */
    private function defaultContentType(): string {
        foreach(array_keys($this->config()->get('response.default.type',[])) as $type)
            if(!str_contains($type,'*'))
                return $type;
        return '*/*';
    }

    /**
     * 协商失败策略
     *
     * - `strict`: 客户端明确给出的 Accept 都无法满足时返回 406
     * - `fallback`: 保持旧行为, 按默认内容类型回落
     *
     * @access private
     * @return string
     */
    private function negotiationFailurePolicy(): string {
        return $this->config()->get('response.default.negotiation','strict')==='fallback'
            ?'fallback'
            :'strict';
    }

    /**
     * 按请求准备响应
     *
     * @access public
     * @param Request $request 请求对象
     * @return void
     */
    public function prepare(Request $request): void {
        // 未显式指定内容类型时按 Accept 头协商
        if($this->contentType==='*/*') {
            $accept=trim((string)$request->header('accept'));
            $type=Negotiator::best(
                Negotiator::parse($accept),
                array_keys($this->config()->get('response.default.type',array()))
            );
            if($type!==null) {
                $this->contentType($type);
            } elseif($accept!==''&&$this->status()>=200&&$this->status()<300
                &&!$this->hasNoBody()&&$this->negotiationFailurePolicy()==='strict') {
                // 客户端明确列出的类型都不可用: 不再静默回落
                HttpError::notAcceptable()->apply($this);
            }
            // 内容类型随请求头变化: 告知缓存; 重复 prepare 不重复追加
            $vary=array_map('trim',explode(',',$this->header('Vary')));
            if(!in_array('Accept',$vary,true)&&!in_array('*',$vary,true))
                $this->addHeader('Vary','Accept');
        }
        // HEAD 只发送响应头(HTTP 规范要求)
        $this->head_only=$request->method()==='HEAD';
    }

    /**
     * 获取或设置单个Header
     *
     * - 读取时同名多值以 `, ` 连接为一行(等价于 HTTP 的合并写法)
     *
     * @access public
     * @param string $name Header名
     * @param string|array|null $value 值(数组为多值; null 时仅读取)
     * @return string
     */
    public function header(
        string $name,
        string|array|null $value=null
    ): string {
        if(func_num_args()>1) {
            // 显式传 null 表示移除该Header
            if($value===null)
                $this->headers->delete($name);
            else
                $this->headers->set($name,self::normalizeHeaderValues($value));
        }
        return implode(', ',(array)$this->headers->get($name,array()));
    }

    /**
     * 追加一个Header值
     *
     * @access public
     * @param string $name Header名
     * @param string $value 值
     * @return void
     */
    public function addHeader(string $name,string $value): void {
        $values=(array)$this->headers->get($name,array());
        $values[]=$value;
        $this->headers->set($name,$values);
    }

    /**
     * 批量设置Header
     *
     * @access public
     * @param array<string,string|array> $headers Header数组(值为数组即多值)
     * @return void
     */
    public function headers(array $headers): void {
        foreach($headers as $name=>$value) {
            $this->headers->set($name,self::normalizeHeaderValues($value));
        }
    }

    /**
     * 归一化Header值
     *
     * @access private
     * @param string|array $value 值
     * @return array<string>
     */
    private static function normalizeHeaderValues(string|array $value): array {
        if(is_array($value))
            return array_map('strval',$value);
        return array($value);
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
        if(func_num_args()>1&&$value!==null) {
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
     * @return string
     */
    public function render(): string {
        // 1xx / 204 / 304 不允许有正文
        if($this->hasNoBody()) return '';
        if($this->return_content!==null) return $this->return_content;
        // 未登记的内容类型回落到登记表的第一个
        $type=$this->getStandardContentType();
        // `*/*` 只是分派桶, 实际落盘时回退到第一个具体类型
        if($type==='*/*')
            $type=$this->defaultContentType();
        $config=$this->config()->get('response.default.type.'.$type,[]);
        $class=$config['class']??Http::class;
        // 处理器需要写入本实例: 显式传入, 不依赖容器中的同名单例
        App::new($class,response:$this,config:$config)->handle();
        // 合并该内容类型登记的Header
        $this->headers($config['headers']??[]);
        return $this->return_content??'';
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
            // 同名多值逐行发送: 首个替换同名头, 其余追加(否则会被 header() 替换掉)
            foreach($this->headers as $key=>$values) {
                $replace=true;
                foreach((array)$values as $value) {
                    header($key.': '.$value,$replace);
                    $replace=false;
                }
            }
            $cookie=App::get(Cookie::class);
            $cookie->setByArray($this->cookies->all());
        }
    }

    /**
     * 结束响应并发送数据
     *
     * @access public
     * @return void
     */
    public function send(): void {
        $content=$this->render();
        // 发送请求头
        $this->sendHeaders();
        // HEAD 只发送头部, 不输出响应体
        if($this->head_only||$this->hasNoBody())
            return;
        // 渲染结果
        echo $content;
    }

    /**
     * 判断当前状态码是否禁止响应正文
     *
     * @access private
     * @return bool
     */
    private function hasNoBody(): bool {
        $code=$this->status();
        return ($code>=100&&$code<200)||$code===204||$code===304;
    }

}
