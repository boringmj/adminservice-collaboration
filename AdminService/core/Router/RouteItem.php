<?php

namespace AdminService\Router;

use AdminService\Exception;

use function array_key_exists;
use function array_reverse;
use function array_slice;
use function count;
use function in_array;
use function is_array;
use function ltrim;
use function preg_match;
use function preg_match_all;
use function preg_quote;
use function preg_replace;
use function rtrim;
use function str_contains;
use function strlen;
use function strtoupper;
use function substr;

/**
 * 单条路由
 *
 * - 承载方法、路径、处理器与中间件
 * - 路径中的 `{name}`、`{name:约束}`、`{name?}` 在构造时编译为具名捕获组
 * - 可选参数(带 `?`)可多个, 但须构成 `/` 分隔的末尾序列, 如 `/a/{b?}/{c?}`
 */
final class RouteItem {

    /**
     * 路径参数模式(含捕获组: 参数名 / 可选标记 / 约束)
     * @var string
     */
    private const PARAM_PATTERN='/\{([a-zA-Z_][a-zA-Z0-9_]*)(\?)?(?::([^}]+))?\}/';

    /**
     * 允许的请求方法(为空或含 `*` 表示不限方法)
     * @var array<string>
     */
    private array $methods;

    /**
     * 路由路径
     * @var string
     */
    private string $path;

    /**
     * 处理器
     * @var mixed
     */
    private mixed $handler;

    /**
     * 路由级中间件
     * @var array<string|object>
     */
    private array $middlewares;

    /**
     * 路由名
     * @var string|null
     */
    private ?string $name=null;

    /**
     * 编译后的匹配正则
     * @var string
     */
    private string $pattern;

    /**
     * 路径参数名列表
     * @var array<string>
     */
    private array $params=array();

    /**
     * 可选参数名列表
     * @var array<string>
     */
    private array $optional=array();

    /**
     * 字面量字符数(路径去掉占位符后的长度, 用于具体度排序)
     * @var int
     */
    private int $literalLength=0;

    /**
     * 构造方法
     *
     * @access public
     * @param array<string> $methods 允许的请求方法(传 `*` 表示不限)
     * @param string $path 路由路径
     * @param mixed $handler 处理器
     * @param array<string|object> $middlewares 路由级中间件
     * @throws Exception 路径参数名重复或路径格式非法
     */
    public function __construct(array $methods,string $path,mixed $handler,array $middlewares=array()) {
        $this->methods=array();
        foreach($methods as $method)
            $this->methods[]=strtoupper((string)$method);
        $this->path=self::normalizePath($path);
        $this->handler=$handler;
        $this->middlewares=$middlewares;
        $this->pattern=$this->compile();
    }

    /**
     * 判断是否允许指定请求方法
     *
     * @access public
     * @param string $method 请求方法
     * @return bool
     */
    public function matchesMethod(string $method): bool {
        if($this->methods===array()||in_array('*',$this->methods,true))
            return true;
        return in_array(strtoupper($method),$this->methods,true);
    }

    /**
     * 匹配路径并提取参数
     *
     * @access public
     * @param string $uri 请求路径
     * @return array<string,string>|null 匹配失败返回 null
     */
    public function matchUri(string $uri): ?array {
        // 未参与匹配的可选参数为 null, 据此与「匹配到空值」区分
        if(preg_match($this->pattern,$uri,$matches,PREG_UNMATCHED_AS_NULL)!==1)
            return null;
        $params=array();
        foreach($this->params as $name) {
            // 可选参数未提供时不注入, 交由控制器形参默认值生效
            if($matches[$name]===null)
                continue;
            $params[$name]=$matches[$name];
        }
        return $params;
    }

    /**
     * 是否为静态路径(不含参数)
     *
     * @access public
     * @return bool
     */
    public function isStatic(): bool {
        return $this->params===array();
    }

    /**
     * 获取匹配形状
     *
     * - 编译正则去掉具名捕获组名, 用于识别「匹配行为等价」的路由
     * - 如 `/user/{id}` 与 `/user/{uid}` 形状相同, 二者会互相遮蔽
     *
     * @access public
     * @return string
     */
    public function getShape(): string {
        return preg_replace('/\?P<[a-zA-Z_][a-zA-Z0-9_]*>/','',$this->pattern);
    }

    /**
     * 按参数反向生成路径
     *
     * @access public
     * @param array<string,mixed> $params 路径参数
     * @return string
     * @throws Exception 缺少路径参数
     */
    public function buildUri(array $params=array()): string {
        $uri='';
        $offset=0;
        $matches=array();
        preg_match_all(self::PARAM_PATTERN,$this->path,$matches,PREG_SET_ORDER|PREG_OFFSET_CAPTURE);
        $count=count($matches);
        foreach($matches as $index=>$match) {
            $name=$match[1][0];
            $optional=($match[2][1]??-1)!==-1;
            $literal=substr($this->path,$offset,$match[0][1]-$offset);
            if(!array_key_exists($name,$params)) {
                if(!$optional)
                    throw new Exception('Missing route parameter.',-412,array(
                        'path'=>$this->path,
                        'name'=>$name
                    ));
                // 末尾可选参数成链: 省略某个之后, 更靠后的参数不得再提供
                for($later=$index+1;$later<$count;$later++)
                    if(array_key_exists($matches[$later][1][0],$params))
                        throw new Exception('Route parameter cannot be provided after an omitted optional one.',-412,array(
                            'path'=>$this->path,
                            'omitted'=>$name,
                            'name'=>$matches[$later][1][0]
                        ));
                $uri.=rtrim($literal,'/');
                $offset=strlen($this->path);
                break;
            }
            $uri.=$literal.(string)$params[$name];
            $offset=$match[0][1]+strlen($match[0][0]);
        }
        return $uri.substr($this->path,$offset);
    }

    /**
     * 设置路由名
     *
     * @access public
     * @param string $name 路由名
     * @return static
     */
    public function name(string $name): static {
        $this->name=$name;
        return $this;
    }

    /**
     * 追加路由级中间件
     *
     * - 追加在全局与分组中间件之后, 执行顺序上更靠近控制器
     *
     * @access public
     * @param string|object|array<string|object> $middlewares 中间件(类名或实例)
     * @return static
     */
    public function middleware(string|object|array $middlewares): static {
        foreach(is_array($middlewares)?$middlewares:array($middlewares) as $middleware)
            $this->middlewares[]=$middleware;
        return $this;
    }

    /**
     * 获取路由名
     *
     * @access public
     * @return string|null
     */
    public function getName(): ?string {
        return $this->name;
    }

    /**
     * 获取处理器
     *
     * @access public
     * @return mixed
     */
    public function getHandler(): mixed {
        return $this->handler;
    }

    /**
     * 获取路由级中间件
     *
     * @access public
     * @return array<string|object>
     */
    public function getMiddlewares(): array {
        return $this->middlewares;
    }

    /**
     * 获取路由路径
     *
     * @access public
     * @return string
     */
    public function getPath(): string {
        return $this->path;
    }

    /**
     * 获取允许的请求方法
     *
     * @access public
     * @return array<string>
     */
    public function getMethods(): array {
        return $this->methods;
    }

    /**
     * 获取路径参数名列表
     *
     * @access public
     * @return array<string>
     */
    public function getParamNames(): array {
        return $this->params;
    }

    /**
     * 获取字面量字符数
     *
     * - 越大表示路径越具体, 用于匹配优先级排序
     *
     * @access public
     * @return int
     */
    public function getLiteralLength(): int {
        return $this->literalLength;
    }

    /**
     * 编译路径为匹配正则
     *
     * - 按占位符出现位置切分路径, 字面量转义后与捕获组交替拼接
     * - 可选参数须构成 `/` 分隔的**末尾**序列, 从右到左嵌套:
     *   如 `/a/{b?}/{c?}` 编译为 `/a(?:/(?P<b>..)(?:/(?P<c>..))?)?`
     *
     * @access private
     * @return string
     * @throws Exception 参数名重复、路径格式非法或可选参数位置有误
     */
    private function compile(): string {
        $tokens=$this->tokenize();
        $first=$this->firstOptional($tokens);
        if($first===null) {
            $pattern='';
            foreach($tokens as $token)
                $pattern.=$this->renderToken($token);
            return $this->finalizePattern($pattern);
        }
        // 可选序列之前的头部: 末位字面量的结尾 `/` 由可选链吸收
        $head=array_slice($tokens,0,$first);
        $last=array_pop($head);
        if($last===null||$last['type']!=='literal'||substr($last['value'],-1)!=='/')
            throw new Exception('Optional route parameter must follow a `/` segment.',-416,array(
                'path'=>$this->path
            ));
        $pattern='';
        foreach($head as $token)
            $pattern.=$this->renderToken($token);
        $pattern.=$this->quoteLiteral(substr($last['value'],0,-1));
        return $this->finalizePattern($pattern.$this->renderOptionalTail(array_slice($tokens,$first)));
    }

    /**
     * 切分路径为令牌序列(字面量与参数交替)
     *
     * @access private
     * @return array<array<string,mixed>>
     */
    private function tokenize(): array {
        $tokens=array();
        $offset=0;
        $matches=array();
        preg_match_all(self::PARAM_PATTERN,$this->path,$matches,PREG_SET_ORDER|PREG_OFFSET_CAPTURE);
        foreach($matches as $match) {
            $literal=substr($this->path,$offset,$match[0][1]-$offset);
            if($literal!=='') {
                $this->literalLength+=strlen($literal);
                $tokens[]=array('type'=>'literal','value'=>$literal);
            }
            $tokens[]=array(
                'type'=>'param',
                'name'=>$match[1][0],
                'optional'=>($match[2][1]??-1)!==-1,
                // 未提供约束时匹配单个路径段
                'constraint'=>($match[3][1]??-1)!==-1?$match[3][0]:'[^/]+'
            );
            $offset=$match[0][1]+strlen($match[0][0]);
        }
        $tail=substr($this->path,$offset);
        if($tail!=='') {
            $this->literalLength+=strlen($tail);
            $tokens[]=array('type'=>'literal','value'=>$tail);
        }
        return $tokens;
    }

    /**
     * 定位首个可选参数在令牌序列中的位置
     *
     * @access private
     * @param array<array<string,mixed>> $tokens 令牌序列
     * @return int|null
     */
    private function firstOptional(array $tokens): ?int {
        foreach($tokens as $index=>$token)
            if($token['type']==='param'&&$token['optional'])
                return $index;
        return null;
    }

    /**
     * 渲染字面量或必填参数令牌
     *
     * @access private
     * @param array<string,mixed> $token 令牌
     * @return string
     * @throws Exception 参数名重复或字面量非法
     */
    private function renderToken(array $token): string {
        if($token['type']==='literal')
            return $this->quoteLiteral($token['value']);
        return $this->renderParam($token);
    }

    /**
     * 渲染参数捕获组并登记参数名
     *
     * @access private
     * @param array<string,mixed> $token 参数令牌
     * @return string
     * @throws Exception 参数名重复
     */
    private function renderParam(array $token): string {
        $name=$token['name'];
        if(in_array($name,$this->params,true))
            throw new Exception('Duplicate route parameter.',-413,array(
                'path'=>$this->path,
                'name'=>$name
            ));
        $this->params[]=$name;
        if($token['optional'])
            $this->optional[]=$name;
        return '(?P<'.$name.'>'.$token['constraint'].')';
    }

    /**
     * 渲染可选参数尾部(从右到左嵌套)
     *
     * - 尾部须为 `/` 分隔的可选参数序列, 形如 `/{b?}/{c?}`
     *
     * @access private
     * @param array<array<string,mixed>> $tokens 以首个可选参数起始的令牌序列
     * @return string
     * @throws Exception 可选参数序列格式非法
     */
    private function renderOptionalTail(array $tokens): string {
        $count=count($tokens);
        $groups=array();
        for($i=0;$i<$count;$i+=2) {
            $token=$tokens[$i];
            $next=$tokens[$i+1]??null;
            if($token['type']!=='param'||!$token['optional']||($next!==null&&($next['type']!=='literal'||$next['value']!=='/')))
                throw new Exception('Optional route parameters must form a `/` separated trailing sequence.',-416,array(
                    'path'=>$this->path
                ));
            // 按路径顺序登记, 保证参数名列表与书写顺序一致
            $groups[]=$this->renderParam($token);
        }
        $pattern='';
        foreach(array_reverse($groups) as $group)
            $pattern='(?:/'.$group.$pattern.')?';
        return $pattern;
    }

    /**
     * 包裹编译结果并校验约束合法性
     *
     * @access private
     * @param string $pattern 拼接后的正则片段
     * @return string
     * @throws Exception 约束中的正则非法
     */
    private function finalizePattern(string $pattern): string {
        $pattern='#^'.$pattern.'$#u';
        // 约束中的非法正则会让编译结果不可用, 在构造期拦下
        if(@preg_match($pattern,'')===false)
            throw new Exception('Invalid route path constraint.',-414,array(
                'path'=>$this->path
            ));
        return $pattern;
    }

    /**
     * 转义路径字面量
     *
     * - 残留花括号说明占位符写法有误, 不做静默降级
     *
     * @access private
     * @param string $literal 字面量
     * @return string
     * @throws Exception 路径格式非法
     */
    private function quoteLiteral(string $literal): string {
        if(str_contains($literal,'{')||str_contains($literal,'}'))
            throw new Exception('Invalid route path.',-414,array(
                'path'=>$this->path
            ));
        return preg_quote($literal,'#');
    }

    /**
     * 规范化路径(补齐前导斜杠, 去掉末尾斜杠)
     *
     * @access private
     * @param string $path 原始路径
     * @return string
     */
    private static function normalizePath(string $path): string {
        $path='/'.ltrim($path,'/');
        return $path==='/'?$path:rtrim($path,'/');
    }

}
