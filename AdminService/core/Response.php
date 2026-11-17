<?php

namespace AdminService;

use AdminService\Config\Repository;
use base\ConfigInterface;
use base\Container as ContainerContract;
use base\Cookie as CookieContract;
use base\Request;
use base\Response as BaseResponse;
use AdminService\ResponseProcessor\Http;
use AdminService\exception\ClientDisconnectedException;

use function array_keys;
use function array_map;
use function array_merge;
use function connection_aborted;
use function error_log;
use function explode;
use function flush;
use function func_num_args;
use function get_class;
use function header;
use function headers_sent;
use function http_response_code;
use function ignore_user_abort;
use function implode;
use function in_array;
use function is_array;
use function ob_flush;
use function ob_get_level;
use function str_contains;
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
     * Cookie 下发服务
     * @var CookieContract|null
     */
    private ?CookieContract $cookie=null;

    /**
     * 请求级容器
     * @var ContainerContract|null
     */
    private ?ContainerContract $container=null;

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
     * 获取 Cookie 下发服务
     *
     * @access private
     * @return CookieContract
     */
    private function cookieWriter(): CookieContract {
        return $this->cookie??=new Cookie($this->config());
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
     * @param CookieContract|null $cookie Cookie 下发服务
     * @param ContainerContract|null $container 请求级容器
     */
    public function __construct(
        ?ConfigInterface $config=null,
        ?CookieContract $cookie=null,
        ?ContainerContract $container=null
    ) {
        $this->config=$config;
        $this->cookie=$cookie;
        $this->container=$container;
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
        if($type==='')
            $type='*/*';
        // 未注册类型回落到默认桶
        if(!$this->isRegisteredType($type))
            return $this->typeList()[0]??'*/*';
        // 精确登记的类型原样返回, vendor JSON 回落到标准 JSON
        return in_array($type,$this->typeList(),true)?$type:'application/json';
    }

    /**
     * 取登记的内容类型列表
     *
     * @access private
     * @return array<int,string>
     */
    private function typeList(): array {
        $types=$this->config()->get('response.default.type',[]);
        return is_array($types)?array_keys($types):array();
    }

    /**
     * 内容类型是否已登记
     *
     * - vendor JSON 在登记了 `application/json` 时视为已登记
     *
     * @access private
     * @param string $type 已归一的内容类型
     * @return bool
     */
    private function isRegisteredType(string $type): bool {
        $type_list=$this->typeList();
        if(in_array($type,$type_list,true))
            return true;
        return $this->isVendorJson($type)&&in_array('application/json',$type_list,true);
    }

    /**
     * 是否为等价到 `application/json` 的 vendor 类型
     *
     * @access private
     * @param string $type 已归一的内容类型
     * @return bool
     */
    private function isVendorJson(string $type): bool {
        return $type!=='application/json'&&Negotiator::normalizeType($type)==='application/json';
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
     * 是否启用自动内容协商
     *
     * - `off`: 不读取 Accept, 不触发 406, 也不自动追加 Vary
     *
     * @access private
     * @return bool
     */
    private function negotiationEnabled(): bool {
        return $this->config()->get('response.default.negotiation','strict')!=='off';
    }

    /**
     * 协商失败策略
     *
     * - `strict`: 客户端明确给出的 Accept 都无法满足时返回 406
     * - `fallback`: 保持旧行为, 按默认内容类型回落
     * - `off`: 不进入协商, 由 {@see negotiationEnabled()} 提前短路
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
     * - `negotiation=off` 时不读取 Accept, 不触发 406, 也不自动追加 Vary
     * - 未显式指定内容类型时按 Accept 头协商
     *
     * @access public
     * @param Request $request 请求对象
     * @return void
     */
    public function prepare(Request $request): void {
        // 未显式指定内容类型且启用自动协商时, 按 Accept 头选择类型
        if($this->contentType==='*/*'&&$this->negotiationEnabled()) {
            $accept=trim((string)$request->header('accept'));
            $type=Negotiator::best(
                Negotiator::parse($accept),
                array_keys($this->config()->get('response.default.type',array()))
            );
            if($type!==null) {
                // 协商只改内容类型, 不清除预置的渲染内容
                $rendered=$this->rendered();
                $this->contentType($type);
                if($rendered!==null)
                    $this->rendered($rendered);
            } elseif($accept!==''&&$this->status()>=200&&$this->status()<300
                &&$this->hasResponseBody()&&$this->negotiationFailurePolicy()==='strict') {
                // 客户端明确列出的类型都不可用: 不再静默回落
                HttpError::notAcceptable()->apply($this);
            }
            // 内容类型随请求头变化: 告知缓存; 重复 prepare 不重复追加
            $vary=array_map('strtolower',array_map('trim',explode(',',$this->header('Vary'))));
            if(!in_array('accept',$vary,true)&&!in_array('*',$vary,true))
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
            else {
                self::assertHeaderName($name);
                $this->headers->set($name,self::normalizeHeaderValues($value));
            }
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
        self::assertHeaderName($name);
        $values=(array)$this->headers->get($name,array());
        $values=array_merge($values,self::normalizeHeaderValues($value));
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
            self::assertHeaderName((string)$name);
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
        $values=is_array($value)?array_map('strval',$value):array($value);
        foreach($values as $item)
            if(str_contains($item,"\r")||str_contains($item,"\n"))
                throw new Exception('Header value contains CR/LF.',100404,array(
                    'value'=>$item
                ));
        return $values;
    }

    /**
     * 校验Header名称
     *
     * - 名称不能包含 CR/LF 或冒号, 避免响应头注入
     *
     * @access private
     * @param string $name Header名
     * @return void
     * @throws Exception Header名非法
     */
    private static function assertHeaderName(string $name): void {
        if($name===''||str_contains($name,"\r")||str_contains($name,"\n")||str_contains($name,':'))
            throw new Exception('Header name is invalid.',100404,array(
                'name'=>$name
            ));
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
     * @param bool|null $secure 是否安全传输
     * @param bool|null $httponly 是否仅http传输
     * @param string|null $samesite 跨站发送策略
     * @return string
     */
    public function cookie(
        string $name,
        ?string $value=null,
        ?int $expire=null,
        ?string $path=null,
        ?string $domain=null,
        ?bool $secure=null,
        ?bool $httponly=null,
        ?string $samesite=null
    ): string {
        if(func_num_args()>1&&$value!==null) {
            $this->cookies->set($name,array(
                'value'=>(string)$value,
                'expire'=>$expire,
                'path'=>$path,
                'domain'=>$domain,
                'secure'=>$secure,
                'httponly'=>$httponly,
                'samesite'=>$samesite
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
                    $value['httponly']??null,
                    $value['samesite']??null
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
        // 1xx / 204 / 205 / 304 不允许有正文
        if($this->hasNoBody()) return '';
        // 先取整棵类型表再按数组键取值: 媒体类型本身可能含点(如 vendor `+json`)
        list(,$config)=$this->typeConfig();
        if($this->return_content!==null) {
            // 预置内容同样补该类型的响应头
            $this->applyTypeHeaders($config);
            return $this->return_content;
        }
        $class=$config['class']??Http::class;
        // 处理器需要写入本实例: 显式传入; 请求级容器负责解析处理器依赖
        $container=$this->container??ContextStorage::current()?->container();
        if($container!==null)
            $container->build($class,response:$this,config:$config)->handle();
        else
            (new $class(response:$this,config:$config))->handle();
        // 合并该内容类型登记的Header; 显式未登记类型原样下发
        $this->applyTypeHeaders($config);
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
            if($this->cookies->all()!==array())
                $this->cookieWriter()->setByArray($this->cookies->all());
        }
    }

    /**
     * 结束响应并发送数据
     *
     * @access public
     * @return void
     */
    public function send(): void {
        if($this->hasStream()) {
            $this->sendStream();
            return;
        }
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
     * 发送流式响应
     *
     * - 先发送请求头, 再按生成器逐段输出并刷新
     * - HEAD / 禁止正文的状态码不调用生成器; 生成器异常只记录日志(响应头可能已发送)
     * - 发送期间临时打开 `ignore_user_abort`: `stop` 在检测到断开后抛异常停止生成,
     *   `continue` 让生成器执行完毕; 结束后恢复原值
     *
     * @access private
     * @return void
     */
    private function sendStream(): void {
        $this->streamHeaders();
        $this->sendHeaders();
        if($this->head_only||$this->hasNoBody())
            return;
        $producer=$this->streamProducer();
        if($producer===null)
            return;
        $continue=$this->streamOnDisconnect()==='continue';
        $previous_abort=ignore_user_abort(true);
        $aborted=false;
        $writer=static function(string $chunk) use (&$aborted,$continue): void {
            if($chunk===''||$aborted)
                return;
            echo $chunk;
            if(ob_get_level()>0)
                ob_flush();
            flush();
            if(connection_aborted()===0)
                return;
            $aborted=true;
            if(!$continue)
                throw new ClientDisconnectedException('Client disconnected during stream response.');
        };
        try {
            $producer($writer);
        } catch(ClientDisconnectedException) {
            // 客户端已断开, 正文无法继续
        } catch(\Throwable $e) {
            error_log('Stream response failed: '.get_class($e).': '.$e->getMessage());
        } finally {
            ignore_user_abort((bool)$previous_abort);
        }
    }

    /**
     * 合并流式响应的内容类型Header
     *
     * - 与 `render()` 使用同一份类型登记表, 但不执行处理器
     *
     * @access private
     * @return void
     */
    private function streamHeaders(): void {
        list(,$config)=$this->typeConfig();
        $this->applyTypeHeaders($config);
    }

    /**
     * 应用内容类型对应的响应头
     *
     * - 显式指定但未登记的类型原样下发 `Content-Type`, 不套用登记表的响应头
     *
     * @access private
     * @param array<string,mixed> $config 类型登记配置
     * @return void
     */
    private function applyTypeHeaders(array $config): void {
        $raw=strtolower(trim(explode(';',$this->contentType,2)[0]));
        if($raw!==''&&$raw!=='*/*'&&!$this->isRegisteredType($raw)) {
            $this->header('Content-Type',$this->contentType);
            return;
        }
        $this->headers($config['headers']??[]);
    }

    /**
     * 取内容类型与登记配置
     *
     * - 未登记类型回落到登记表第一个; 通配桶只作分派, 落盘时回退到第一个具体类型
     *
     * @access private
     * @param string|null $type 内容类型(null = 当前类型)
     * @return array{0:string,1:array<string,mixed>} 实际类型与登记配置
     */
    private function typeConfig(?string $type=null): array {
        $type=$type??$this->getStandardContentType();
        if($type==='*/*')
            $type=$this->defaultContentType();
        $types=$this->config()->get('response.default.type',[]);
        return array($type,is_array($types)?($types[$type]??[]):array());
    }

    /**
     * 导出可直接写入非 SAPI 响应的内容
     *
     * - `prepare` 执行一次; 普通响应再执行 `render`, 流式响应只返回生成器
     * - HEAD 与禁止正文的状态码返回空正文; 流式响应的生成器为 null
     *
     * @access public
     * @param Request $request 当前请求
     * @return array{
     *     status:int,
     *     headers:array<string,array<int,string>>,
     *     cookies:array<string,array{
     *         value:string,
     *         expire:int,
     *         path:string,
     *         domain:string,
     *         secure:bool,
     *         httponly:bool,
     *         samesite:string
     *     }>,
     *     body:string,
     *     stream:callable(callable(string):void):void|null,
     *     stream_on_disconnect:'stop'|'continue'
     * }
     */
    public function export(Request $request): array {
        $this->prepare($request);
        $cookies=$this->cookieWriter()->buildByArray($this->cookies->all());
        if($this->hasStream()) {
            $this->streamHeaders();
            return array(
                'status'=>$this->status(),
                'headers'=>$this->headers->all(),
                'cookies'=>$cookies,
                'body'=>'',
                'stream'=>$this->head_only||$this->hasNoBody()?null:$this->streamProducer(),
                'stream_on_disconnect'=>$this->streamOnDisconnect()
            );
        }
        $content=$this->render();
        if($this->head_only)
            $content='';
        return array(
            'status'=>$this->status(),
            'headers'=>$this->headers->all(),
            'cookies'=>$cookies,
            'body'=>$content,
            'stream'=>null,
            'stream_on_disconnect'=>$this->streamOnDisconnect()
        );
    }

    /**
     * 判断当前响应是否有可协商的内容
     *
     * - 已渲染内容、已设置的流式生成器或控制器返回值非空时视为有内容
     * - `null` 与空字符串不算内容; 数组、对象、0、false 仍参与协商
     *
     * @access private
     * @return bool
     */
    private function hasResponseBody(): bool {
        if($this->hasNoBody())
            return false;
        if($this->hasStream())
            return true;
        $rendered=$this->rendered();
        if($rendered!==null&&$rendered!=='')
            return true;
        $body=$this->body();
        return $body!==null&&$body!=='';
    }

    /**
     * 判断当前状态码是否禁止响应正文
     *
     * @access private
     * @return bool
     */
    private function hasNoBody(): bool {
        $code=$this->status();
        return ($code>=100&&$code<200)||$code===204||$code===205||$code===304;
    }

}
