<?php

namespace AdminService\Router;

use AdminService\Exception;

use function array_key_exists;
use function in_array;
use function ltrim;
use function preg_match;
use function preg_match_all;
use function preg_quote;
use function preg_replace_callback;
use function rtrim;
use function str_contains;
use function strlen;
use function strtoupper;
use function substr;

/**
 * 单条路由
 *
 * - 承载方法、路径、处理器与中间件
 * - 路径中的 `{name}` 与 `{name:约束}` 在构造时编译为具名捕获组
 */
final class RouteItem {

    /**
     * 路径参数模式(含捕获组, 用于提取参数名与约束)
     * @var string
     */
    private const PARAM_PATTERN='/\{([a-zA-Z_][a-zA-Z0-9_]*)(?::([^}]+))?\}/';

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
        if(preg_match($this->pattern,$uri,$matches)!==1)
            return null;
        $params=array();
        foreach($this->params as $name)
            $params[$name]=$matches[$name]??null;
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
     * 按参数反向生成路径
     *
     * @access public
     * @param array<string,mixed> $params 路径参数
     * @return string
     * @throws Exception 缺少路径参数
     */
    public function buildUri(array $params=array()): string {
        return preg_replace_callback(self::PARAM_PATTERN,function(array $matches) use ($params): string {
            $name=$matches[1];
            if(!array_key_exists($name,$params))
                throw new Exception('Missing route parameter.',-412,array(
                    'path'=>$this->path,
                    'name'=>$name
                ));
            return (string)$params[$name];
        },$this->path);
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
     * 编译路径为匹配正则
     *
     * - 按占位符出现位置切分路径, 字面量转义后与捕获组交替拼接
     * - 参数约束作为捕获组直接进入正则, 非法约束在编译期暴露
     *
     * @access private
     * @return string
     * @throws Exception 参数名重复或路径格式非法
     */
    private function compile(): string {
        $pattern='';
        $offset=0;
        $matches=array();
        preg_match_all(self::PARAM_PATTERN,$this->path,$matches,PREG_SET_ORDER|PREG_OFFSET_CAPTURE);
        foreach($matches as $match) {
            $pattern.=$this->quoteLiteral(substr($this->path,$offset,$match[0][1]-$offset));
            $name=$match[1][0];
            if(in_array($name,$this->params,true))
                throw new Exception('Duplicate route parameter.',-413,array(
                    'path'=>$this->path,
                    'name'=>$name
                ));
            $this->params[]=$name;
            // 未提供约束时匹配单个路径段
            $constraint=($match[2][1]??-1)!==-1?$match[2][0]:'[^/]+';
            $pattern.='(?P<'.$name.'>'.$constraint.')';
            $offset=$match[0][1]+strlen($match[0][0]);
        }
        $pattern.=$this->quoteLiteral(substr($this->path,$offset));
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
