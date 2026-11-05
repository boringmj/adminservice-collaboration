<?php

namespace AdminService\Router;

use AdminService\Exception;
use AdminService\Pipeline;
use base\Attribute\Method;
use base\Attribute\Middleware;
use base\Attribute\RouteParam;
use Closure;
use ReflectionClass;
use ReflectionException;
use ReflectionFunction;
use ReflectionFunctionAbstract;
use ReflectionMethod;

use function array_key_exists;
use function array_diff_key;
use function array_map;
use function array_merge;
use function array_pop;
use function array_reverse;
use function array_slice;
use function array_values;
use function class_exists;
use function count;
use function explode;
use function http_build_query;
use function implode;
use function in_array;
use function is_array;
use function is_numeric;
use function is_object;
use function is_string;
use function ltrim;
use function method_exists;
use function preg_match;
use function preg_quote;
use function preg_replace;
use function preg_replace_callback;
use function rawurlencode;
use function rtrim;
use function str_contains;
use function strlen;
use function strpos;
use function strtoupper;
use function substr;

/**
 * 单条路由
 *
 * - 承载方法、路径、处理器、参数绑定与中间件
 * - 中间件按层分开放置: 分组层({@see groupMiddleware()})与路由层({@see middleware()}), 各层内部按 `priority` 排序
 * - 路径中的 `{name}`、`{name:约束}`、`{name?}` 在构造时编译为具名捕获组
 * - 路由级参数绑定用 {@see bind()}; 控制器形参上的 `#[RouteParam]` 优先于路由级绑定
 * - 可选参数(带 `?`)可多个, 但须构成 `/` 分隔的末尾序列, 如 `/a/{b?}/{c?}`
 * - 末尾的 `*` 为通配糖: `/files/*` 等价于 `/files/{any?:.*}`
 */
final class RouteItem {

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
     * 分组层中间件(归一化条目)
     * @var array<array{middleware:string|object,priority:int}>
     */
    private array $group_middlewares=array();

    /**
     * 路由层中间件(归一化条目)
     * @var array<array{middleware:string|object,priority:int}>
     */
    private array $route_middlewares=array();

    /**
     * 路由名
     * @var string|null
     */
    private ?string $name=null;

    /**
     * 路由级参数绑定(占位符名 => 控制器形参名)
     * @var array<string,string>
     */
    private array $bindings=array();

    /**
     * 合并参数级属性后的绑定缓存
     * @var array<string,string>|null
     */
    private ?array $resolved_bindings=null;

    /**
     * 已编译的控制器级中间件
     * @var array<string|object>|null
     */
    private ?array $compiled_controller_middlewares=null;

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
     * 参数名到约束的映射(反向生成时校验取值用)
     * @var array<string,string>
     */
    private array $constraints=array();

    /**
     * 字面量字符数(路径去掉占位符后的长度, 用于具体度排序)
     * @var int
     */
    private int $literalLength=0;

    /**
     * 构造方法
     *
     * @access public
     * @param array<string|Method> $methods 允许的请求方法(枚举 / 字符串, 含 `*` 表示不限)
     * @param string $path 路由路径
     * @param mixed $handler 处理器
     * @param mixed $middlewares 分组层中间件(类名 / 实例 / 数组 / 含 priority 的条目)
     * @param bool $compile_pattern 是否立即编译匹配正则(从缓存恢复时可跳过)
     * @throws Exception 路径参数名重复或路径格式非法
     * @throws \InvalidArgumentException 请求方法不是非空字符串或 Method 枚举
     */
    public function __construct(
        array $methods,
        string $path,
        mixed $handler,
        mixed $middlewares=array(),
        bool $compile_pattern=true
    ) {
        $this->methods=array();
        foreach($methods as $method)
            $this->methods[]=Method::normalize($method);
        $this->path=self::expandWildcard(self::normalizePath($path));
        $this->handler=$handler;
        $this->group_middlewares=Pipeline::normalize($middlewares);
        $this->pattern=$compile_pattern?$this->compile():'';
    }

    /**
     * 判断是否允许指定请求方法
     *
     * - HEAD 由 GET 路由承接(HTTP 规范要求服务器支持 HEAD)
     *
     * @access public
     * @param string $method 请求方法
     * @return bool
     */
    public function matchesMethod(string $method): bool {
        return self::matchesMethodData($this->methods,$method);
    }

    /**
     * 校验编译路由数据
     *
     * @access public
     * @param array<string,mixed> $data 编译路由数据
     * @return void
     * @throws Exception 数据字段非法
     */
    public static function validateData(array $data): void {
        self::normalizeData($data);
    }

    /**
     * 规范化编译路由数据
     *
     * - 路径和方法与 RouteItem::__construct() 使用同一套规范化规则
     * - 懒加载导入必须先经过此方法, 避免原始数据直接进入匹配
     *
     * @access public
     * @param array<string,mixed> $data 编译路由数据
     * @return array<string,mixed>
     * @throws Exception 数据字段非法
     */
    public static function normalizeData(array $data): array {
        if(!isset($data['path'])||!is_string($data['path'])||$data['path']===''
            ||!array_key_exists('handler',$data))
            throw new Exception('Invalid compiled route item.',-423);
        if(!isset($data['pattern'])||!is_string($data['pattern'])||$data['pattern']==='')
            throw new Exception('Invalid compiled route item.',-423);
        $data['path']=self::expandWildcard(self::normalizePath($data['path']));
        $methods=array();
        if(array_key_exists('methods',$data)) {
            if(!is_array($data['methods']))
                throw new Exception('Invalid compiled route item.',-423);
            foreach($data['methods'] as $method) {
                if(!is_string($method)&&!($method instanceof Method))
                    throw new Exception('Invalid compiled route item.',-423);
                try {
                    $methods[]=Method::normalize($method);
                } catch(\InvalidArgumentException) {
                    throw new Exception('Invalid compiled route item.',-423);
                }
            }
        }
        $data['methods']=$methods;
        foreach(array('params','optional') as $key) {
            if(!array_key_exists($key,$data))
                continue;
            if(!is_array($data[$key]))
                throw new Exception('Invalid compiled route item.',-423);
            foreach($data[$key] as $value)
                if(!is_string($value))
                    throw new Exception('Invalid compiled route item.',-423);
            $data[$key]=array_values($data[$key]);
        }
        if(array_key_exists('name',$data)
            &&$data['name']!==null
            &&(!is_string($data['name'])||$data['name']===''))
            throw new Exception('Invalid compiled route item.',-423);
        if(array_key_exists('literal_length',$data)) {
            if(!is_numeric($data['literal_length']))
                throw new Exception('Invalid compiled route item.',-423);
            $data['literal_length']=(int)$data['literal_length'];
        }
        foreach(array('group_middlewares','route_middlewares','bindings','constraints') as $key) {
            if(array_key_exists($key,$data)&&!is_array($data[$key]))
                throw new Exception('Invalid compiled route item.',-423);
        }
        foreach(array('resolved_bindings','controller_middlewares') as $key) {
            if(array_key_exists($key,$data)
                &&$data[$key]!==null
                &&!is_array($data[$key]))
                throw new Exception('Invalid compiled route item.',-423);
        }
        return $data;
    }

    /**
     * 按方法列表判断是否允许指定请求方法
     *
     * @access public
     * @param array<string> $methods 方法列表
     * @param string $method 请求方法
     * @return bool
     */
    public static function matchesMethodData(array $methods,string $method): bool {
        if($methods===array()||in_array('*',$methods,true))
            return true;
        $method=strtoupper($method);
        if(in_array($method,$methods,true))
            return true;
        return $method==='HEAD'&&in_array('GET',$methods,true);
    }

    /**
     * 获取本路由允许的请求方法(用于 405 的 Allow 响应头)
     *
     * - 声明了 GET 时一并列出 HEAD, 与承接规则保持一致
     * - 不限方法的路由返回空数组, 由调用方按需处理
     *
     * @access public
     * @return array<string>
     */
    public function allowedMethods(): array {
        return self::allowedMethodsData($this->methods);
    }

    /**
     * 按方法列表汇总允许的方法
     *
     * @access public
     * @param array<string> $methods 方法列表
     * @return array<string>
     */
    public static function allowedMethodsData(array $methods): array {
        if($methods===array()||in_array('*',$methods,true))
            return array();
        if(in_array('GET',$methods,true)&&!in_array('HEAD',$methods,true))
            $methods[]='HEAD';
        return $methods;
    }

    /**
     * 匹配路径并提取参数
     *
     * @access public
     * @param string $uri 请求路径
     * @return array<string,string>|null 匹配失败返回 null
     */
    public function matchUri(string $uri): ?array {
        return self::matchUriData($this->pattern,$this->params,$uri);
    }

    /**
     * 用编译正则匹配路径并提取参数
     *
     * @access public
     * @param string $pattern 编译正则
     * @param array<string> $params 参数名
     * @param string $uri 请求路径
     * @return array<string,string>|null
     */
    public static function matchUriData(string $pattern,array $params,string $uri): ?array {
        // 未参与匹配的可选参数为 null, 据此与「匹配到空值」区分
        if(preg_match($pattern,$uri,$matches,PREG_UNMATCHED_AS_NULL)!==1)
            return null;
        $out=array();
        foreach($params as $name) {
            // 可选参数未提供时不注入, 交由控制器形参默认值生效
            if($matches[$name]===null)
                continue;
            $out[$name]=$matches[$name];
        }
        return $out;
    }

    /**
     * 是否为静态路径(不含参数)
     *
     * @access public
     * @return bool
     */
    public function isStatic(): bool {
        return self::isStaticData($this->params);
    }

    /**
     * 按参数名列表判断是否为静态路径
     *
     * @access public
     * @param array<string> $params 参数名
     * @return bool
     */
    public static function isStaticData(array $params): bool {
        return $params===array();
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
        $used=array();
        $offset=0;
        $matches=$this->parsePlaceholders($this->path);
        $count=count($matches);
        foreach($matches as $index=>$match) {
            $name=$match['name'];
            $optional=$match['optional'];
            $literal=substr($this->path,$offset,$match['offset']-$offset);
            if(!array_key_exists($name,$params)) {
                if(!$optional)
                    throw new Exception('Missing route parameter.',-412,array(
                        'path'=>$this->path,
                        'name'=>$name
                    ));
                // 末尾可选参数成链: 省略某个之后, 更靠后的参数不得再提供
                for($later=$index+1;$later<$count;$later++)
                    if(array_key_exists($matches[$later]['name'],$params))
                        throw new Exception('Route parameter cannot be provided after an omitted optional one.',-412,array(
                            'path'=>$this->path,
                            'omitted'=>$name,
                            'name'=>$matches[$later]['name']
                        ));
                $uri.=rtrim($this->encodeStaticLiteral($literal),'/');
                $offset=strlen($this->path);
                break;
            }
            $uri.=$this->encodeStaticLiteral($literal).$this->encodePathValue($this->checkValue($name,$params[$name]));
            $used[$name]=true;
            $offset=$match['offset']+$match['length'];
        }
        $path=$uri.$this->encodeStaticLiteral(substr($this->path,$offset));
        // 未出现在路径模板里的参数按查询串附加(Laravel 风格), 不再静默丢弃
        $extra=array_diff_key($params,$used);
        if($extra!==array())
            $path.='?'.http_build_query($extra);
        return $path;
    }

    /**
     * 解析路径中的参数占位符
     *
     * - 约束内允许出现花括号量词、字符类和转义字符
     *
     * @access private
     * @param string $path 路由路径
     * @return array<array{offset:int,length:int,name:string,optional:bool,constraint:string}>
     */
    private function parsePlaceholders(string $path): array {
        $matches=array();
        $length=strlen($path);
        $offset=0;
        while(($start=strpos($path,'{',$offset))!==false) {
            if(preg_match('/\G([a-zA-Z_][a-zA-Z0-9_]*)(\?)?/',$path,$name_match,0,$start+1)!==1) {
                $offset=$start+1;
                continue;
            }
            $name=$name_match[1];
            $optional=($name_match[2]??'')==='?';
            $pos=$start+1+strlen($name_match[0]);
            $constraint='';
            if($pos<$length&&$path[$pos]===':') {
                $pos++;
                $constraint_start=$pos;
                $depth=0;
                $in_class=false;
                $escaped=false;
                for(; $pos<$length; $pos++) {
                    $char=$path[$pos];
                    if($escaped) {
                        $escaped=false;
                        continue;
                    }
                    if($char==='\\') {
                        $escaped=true;
                        continue;
                    }
                    if($in_class) {
                        if($char===']')
                            $in_class=false;
                        continue;
                    }
                    if($char==='[') {
                        $in_class=true;
                        continue;
                    }
                    if($char==='{') {
                        $depth++;
                        continue;
                    }
                    if($char==='}') {
                        if($depth>0) {
                            $depth--;
                            continue;
                        }
                        break;
                    }
                }
                if($pos>=$length||$path[$pos]!=='}'||$in_class) {
                    $offset=$start+1;
                    continue;
                }
                $constraint=substr($path,$constraint_start,$pos-$constraint_start);
                if($constraint==='') {
                    $offset=$start+1;
                    continue;
                }
            } elseif($pos>=$length||$path[$pos]!=='}') {
                $offset=$start+1;
                continue;
            }
            $matches[]=array(
                'offset'=>$start,
                'length'=>$pos-$start+1,
                'name'=>$name,
                'optional'=>$optional,
                'constraint'=>$constraint===''?'[^/]+':$constraint
            );
            $offset=$pos+1;
        }
        return $matches;
    }

    /**
     * 编码路径参数值
     *
     * - 保留通配参数中的 `/` 分隔, 其余字符按路径段编码
     *
     * @access private
     * @param string $value 参数值
     * @return string
     */
    private function encodePathValue(string $value): string {
        return implode('/',array_map('rawurlencode',explode('/',$value)));
    }

    /**
     * 编码静态路径字面量
     *
     * - 只处理控制字符、空格、非 ASCII、`?` 与 `#`; 保留已有的 `%XX` 写法
     * - 动态参数值仍由 `encodePathValue()` 负责
     *
     * @access private
     * @param string $literal 静态字面量
     * @return string
     */
    private function encodeStaticLiteral(string $literal): string {
        if($literal===''||preg_match('/[\x00-\x20\x7F-\xFF?#]/',$literal)!==1)
            return $literal;
        return (string)preg_replace_callback(
            '/[\x00-\x20\x7F-\xFF?#]/',
            static function(array $match): string {
                return rawurlencode($match[0]);
            },
            $literal
        );
    }

    /**
     * 校验取值是否满足参数约束
     *
     * - 避免生成必然 404 的 URL(如 `{id:\d+}` 传入 `abc`)
     *
     * @access private
     * @param string $name 参数名
     * @param mixed $value 取值
     * @return string
     * @throws Exception 取值不满足约束
     */
    private function checkValue(string $name,mixed $value): string {
        $value=(string)$value;
        $constraint=$this->constraints[$name]??'[^/]+';
        if(preg_match('#^(?:'.$constraint.')$#u',$value)!==1)
            throw new Exception('Route parameter does not match constraint.',-420,array(
                'path'=>$this->path,
                'name'=>$name,
                'value'=>$value,
                'constraint'=>$constraint
            ));
        return $value;
    }

    /**
     * 设置路由级参数绑定
     *
     * - 参数级 `#[RouteParam]` 会覆盖同一占位符的路由级绑定
     *
     * @access public
     * @param array<string,string> $bindings 占位符名 => 控制器形参名
     * @return static
     * @throws Exception 占位符不存在、目标名非法或目标重复
     */
    public function bind(array $bindings): static {
        if($bindings===array())
            return $this;
        $merged=array_merge($this->bindings,$bindings);
        $targets=array();
        foreach($merged as $source=>$target) {
            if(!is_string($source)||!is_string($target)||$source===''||$target==='')
                throw new Exception('Route parameter binding must use non-empty string names.',-422,array(
                    'path'=>$this->path
                ));
            if(!in_array($source,$this->params,true))
                throw new Exception('Route parameter binding references an unknown parameter.',-422,array(
                    'path'=>$this->path,
                    'name'=>$source
                ));
            if(preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/',$target)!==1)
                throw new Exception('Route parameter binding target is not a valid variable name.',-422,array(
                    'path'=>$this->path,
                    'name'=>$source,
                    'target'=>$target
                ));
            if(array_key_exists($target,$targets))
                throw new Exception('Route parameter binding target is duplicated.',-422,array(
                    'path'=>$this->path,
                    'target'=>$target
                ));
            $targets[$target]=true;
        }
        $this->bindings=$merged;
        $this->resolved_bindings=null;
        return $this;
    }

    /**
     * 获取生效的参数绑定
     *
     * - 合并路由级绑定与控制器形参上的 `#[RouteParam]`
     * - 同一占位符冲突时参数级优先
     *
     * @access public
     * @return array<string,string> 占位符名 => 控制器形参名
     * @throws Exception 绑定引用了不存在的占位符或目标形参
     * @throws ReflectionException 处理器无法反射
     */
    public function getBindings(): array {
        if($this->resolved_bindings!==null)
            return $this->resolved_bindings;
        $reflection=$this->handlerReflection();
        $parameter_bindings=array();
        $parameter_names=array();
        foreach($reflection->getParameters() as $param) {
            $parameter_names[$param->getName()]=true;
            foreach($param->getAttributes(RouteParam::class) as $attribute) {
                /** @var RouteParam $declaration */
                $declaration=$attribute->newInstance();
                $source=$declaration->getName();
                if(array_key_exists($source,$parameter_bindings))
                    throw new Exception('Route parameter binding is declared more than once.',-422,array(
                        'path'=>$this->path,
                        'name'=>$source
                    ));
                $parameter_bindings[$source]=$param->getName();
            }
        }
        $bindings=array_merge($this->bindings,$parameter_bindings);
        $targets=array();
        foreach($bindings as $source=>$target) {
            if(!in_array($source,$this->params,true))
                throw new Exception('Route parameter binding references an unknown parameter.',-422,array(
                    'path'=>$this->path,
                    'name'=>$source
                ));
            if(!array_key_exists($target,$parameter_names))
                throw new Exception('Route parameter binding target is not a handler parameter.',-422,array(
                    'path'=>$this->path,
                    'name'=>$source,
                    'target'=>$target
                ));
            if(array_key_exists($target,$targets))
                throw new Exception('Route parameter binding target is duplicated.',-422,array(
                    'path'=>$this->path,
                    'target'=>$target
                ));
            $targets[$target]=true;
        }
        $this->resolved_bindings=$bindings;
        return $bindings;
    }

    /**
     * 编译控制器级中间件
     *
     * - 结果同时包含配置项、类属性与方法属性, 已按优先级排序
     * - 结果缓存在当前路由项上, 供后续请求直接取用
     *
     * @access public
     * @param array<string,mixed> $configured 配置中的控制器级中间件
     * @return array<string|object>
     */
    public function compileControllerMiddlewares(mixed $configured=array()): array {
        if($this->compiled_controller_middlewares!==null)
            return $this->compiled_controller_middlewares;
        $entries=Pipeline::normalize($configured);
        $handler=$this->handler;
        if(is_array($handler)&&count($handler)===2&&is_string($handler[0])&&class_exists($handler[0])) {
            $reflection=new ReflectionClass($handler[0]);
            $entries=array_merge($entries,self::middlewareEntries($reflection->getAttributes(Middleware::class)));
            if($reflection->hasMethod($handler[1]))
                $entries=array_merge($entries,self::middlewareEntries(
                    $reflection->getMethod($handler[1])->getAttributes(Middleware::class)
                ));
        }
        return $this->compiled_controller_middlewares=Pipeline::order($entries);
    }

    /**
     * 获取已编译的控制器级中间件
     *
     * @access public
     * @return array<string|object>|null 尚未编译时返回 null
     */
    public function getCompiledControllerMiddlewares(): ?array {
        return $this->compiled_controller_middlewares;
    }

    /**
     * 把中间件属性展开为中间件条目
     *
     * @access private
     * @param array<\ReflectionAttribute> $attributes 属性集合
     * @return array<array{middleware:string|object,priority:int}>
     */
    private static function middlewareEntries(array $attributes): array {
        $entries=array();
        foreach($attributes as $attribute) {
            /** @var Middleware $declaration */
            $declaration=$attribute->newInstance();
            foreach($declaration->getMiddlewares() as $middleware)
                $entries[]=array('middleware'=>$middleware,'priority'=>$declaration->getPriority());
        }
        return $entries;
    }

    /**
     * 导出为可缓存数组
     *
     * @access public
     * @return array{
     *     methods:array<string>,
     *     path:string,
     *     handler:mixed,
     *     group_middlewares:array<array{middleware:string|object,priority:int}>,
     *     route_middlewares:array<array{middleware:string|object,priority:int}>,
     *     name:string|null,
     *     bindings:array<string,string>,
     *     resolved_bindings:array<string,string>,
     *     pattern:string,
     *     params:array<string>,
     *     optional:array<string>,
     *     constraints:array<string,string>,
     *     literal_length:int,
     *     controller_middlewares:array<string|object>|null
     * }
     * @throws Exception 参数绑定或处理器无法解析
     */
    public function export(): array {
        return array(
            'methods'=>$this->methods,
            'path'=>$this->path,
            'handler'=>$this->handler,
            'group_middlewares'=>$this->group_middlewares,
            'route_middlewares'=>$this->route_middlewares,
            'name'=>$this->name,
            'bindings'=>$this->bindings,
            'resolved_bindings'=>$this->getBindings(),
            'pattern'=>$this->pattern,
            'params'=>$this->params,
            'optional'=>$this->optional,
            'constraints'=>$this->constraints,
            'literal_length'=>$this->literalLength,
            'controller_middlewares'=>$this->compiled_controller_middlewares
        );
    }

    /**
     * 从缓存数组恢复
     *
     * @access public
     * @param array<string,mixed> $data 缓存数据
     * @return self
     * @throws Exception 数据字段非法
     */
    public static function fromArray(array $data): self {
        $data=self::normalizeData($data);
        $route=new self(
            isset($data['methods'])&&is_array($data['methods'])?$data['methods']:array(),
            $data['path'],
            $data['handler'],
            array(),
            false
        );
        if(isset($data['group_middlewares'])&&is_array($data['group_middlewares']))
            $route->group_middlewares=$data['group_middlewares'];
        if(isset($data['route_middlewares'])&&is_array($data['route_middlewares']))
            $route->route_middlewares=$data['route_middlewares'];
        $route->name=isset($data['name'])&&is_string($data['name'])?$data['name']:null;
        if(isset($data['bindings'])&&is_array($data['bindings']))
            $route->bindings=$data['bindings'];
        $route->resolved_bindings=isset($data['resolved_bindings'])&&is_array($data['resolved_bindings'])
            ?$data['resolved_bindings']
            :null;
        if(isset($data['pattern'])&&is_string($data['pattern'])&&$data['pattern']!=='')
            $route->pattern=$data['pattern'];
        if(isset($data['params'])&&is_array($data['params']))
            $route->params=array_values($data['params']);
        if(isset($data['optional'])&&is_array($data['optional']))
            $route->optional=array_values($data['optional']);
        if(isset($data['constraints'])&&is_array($data['constraints']))
            $route->constraints=$data['constraints'];
        if(array_key_exists('literal_length',$data))
            $route->literalLength=(int)$data['literal_length'];
        $route->compiled_controller_middlewares=isset($data['controller_middlewares'])&&is_array($data['controller_middlewares'])
            ?$data['controller_middlewares']
            :null;
        return $route;
    }

    /**
     * 获取处理器反射
     *
     * @access private
     * @return ReflectionFunctionAbstract
     * @throws Exception 处理器无法反射
     * @throws ReflectionException 处理器类或方法不存在
     */
    private function handlerReflection(): ReflectionFunctionAbstract {
        $handler=$this->handler;
        if(is_array($handler)&&count($handler)===2&&is_string($handler[1]))
            return new ReflectionMethod($handler[0],$handler[1]);
        if($handler instanceof Closure||is_string($handler))
            return new ReflectionFunction($handler);
        if(is_object($handler)&&method_exists($handler,'__invoke'))
            return new ReflectionMethod($handler,'__invoke');
        throw new Exception('Route handler cannot be reflected.',-422,array(
            'path'=>$this->path
        ));
    }

    /**
     * 设置路由名
     *
     * @access public
     * @param string $name 路由名(不能为空字符串)
     * @return static
     * @throws Exception 路由名为空字符串
     */
    public function name(string $name): static {
        if($name==='')
            throw new Exception('Route name must not be empty.',-424,array(
                'path'=>$this->path
            ));
        $this->name=$name;
        return $this;
    }

    /**
     * 追加路由层中间件
     *
     * - 追加在分组层之后, 执行顺序上更靠近控制器
     * - 支持条目写法声明层内优先级: `array('middleware'=>Foo::class,'priority'=>10)`
     *
     * @access public
     * @param mixed $middlewares 中间件(类名 / 实例 / 数组 / 含 priority 的条目)
     * @return static
     */
    public function middleware(mixed $middlewares): static {
        $this->route_middlewares=array_merge(
            $this->route_middlewares,
            Pipeline::normalize($middlewares)
        );
        return $this;
    }

    /**
     * 追加分组的中间件
     *
     * - 用于类级 `#[RouteGroup]` 的声明: 归入分组层, 保持在路由层之外
     *
     * @access public
     * @param mixed $middlewares 中间件(类名 / 实例 / 数组 / 含 priority 的条目)
     * @return static
     */
    public function groupMiddleware(mixed $middlewares): static {
        $this->group_middlewares=array_merge(
            $this->group_middlewares,
            Pipeline::normalize($middlewares)
        );
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
     * 获取分组层中间件(层内已按 priority 排序)
     *
     * @access public
     * @return array<string|object>
     */
    public function getGroupMiddlewares(): array {
        return Pipeline::order($this->group_middlewares);
    }

    /**
     * 获取路由层中间件(层内已按 priority 排序)
     *
     * @access public
     * @return array<string|object>
     */
    public function getRouteMiddlewares(): array {
        return Pipeline::order($this->route_middlewares);
    }

    /**
     * 获取全部中间件(分组层 + 路由层, 便于查看与测试)
     *
     * @access public
     * @return array<string|object>
     */
    public function getMiddlewares(): array {
        return array_merge($this->getGroupMiddlewares(),$this->getRouteMiddlewares());
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
        foreach($this->parsePlaceholders($this->path) as $match) {
            $literal=substr($this->path,$offset,$match['offset']-$offset);
            if($literal!=='') {
                $this->literalLength+=strlen($literal);
                $tokens[]=array('type'=>'literal','value'=>$literal);
            }
            $tokens[]=array(
                'type'=>'param',
                'name'=>$match['name'],
                'optional'=>$match['optional'],
                'constraint'=>$match['constraint']
            );
            $offset=$match['offset']+$match['length'];
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
        $this->constraints[$name]=$token['constraint'];
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
     * 展开末尾通配糖
     *
     * - `/files/*` 等价于 `/files/{any?:.*}`, 即可同时匹配 `/files` 与任意深度子路径
     *
     * @access private
     * @param string $path 原始路径
     * @return string
     * @throws Exception 通配符不在末尾
     */
    private static function expandWildcard(string $path): string {
        $segments=explode('/',$path);
        $last=count($segments)-1;
        // 仅「自成一段的 `*`」才是通配糖, 约束内的 `*`(如 `.*`)不受影响
        foreach($segments as $index=>$segment) {
            if($segment==='*'&&$index!==$last)
                throw new Exception('Wildcard must be the last path segment.',-421,array(
                    'path'=>$path
                ));
        }
        if($segments[$last]!=='*')
            return $path;
        $segments[$last]='{any?:.*}';
        return implode('/',$segments);
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
