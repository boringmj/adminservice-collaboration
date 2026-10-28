<?php

namespace AdminService;

use ReflectionType;
use ReflectionClass;
use ReflectionMethod;
use ReflectionParameter;
use ReflectionNamedType;
use ReflectionUnionType;
use ReflectionIntersectionType;
use ReflectionProperty;
use base\AutowireInterface;
use base\ArgumentResolverInterface;
use AdminService\DynamicProxy;
use base\Attribute\AutowireMode;
use base\Attribute\AutowireSetter;
use base\Attribute\AutowireProperty;
use base\Attribute\AutowireMethod;
use base\Attribute\AutowireLifecycle;
use AdminService\exception\AutowireException;
use Closure;

use function array_merge;
use function array_unique;
use function array_values;
use function count;
use function in_array;
use function is_a;

/**
 * 自动装配器
 *
 * - 装配入口: 属性(`#[AutowireProperty]`)、Setter(`#[AutowireSetter]`)、生命周期方法(`#[AutowireLifecycle]`)、
 *   单依赖方法(`#[AutowireMethod]`)
 * - 注入模式: `Normal` / `Proxy` / `Deferred`; 代理类型不兼容直接抛异常, 延迟任务在根对象登记后统一刷新
 * - 依赖方向: 本类**不依赖容器**; "类名解析(`getRealClass`)"与"按类名装配实例(`make`)"由容器以回调回填,
 *   于是 容器 → 装配器 是单向的
 * - 构建标识 `$flags` 仍以引用传递(防止依赖注入死循环), 与调用方共用同一份栈
 *
 * @access public
 * @package AdminService
 * @version 1.0.0
 */
final class Autowire implements AutowireInterface {

    /**
     * 自动注入目标注解及报错名
     *
     * 集中维护, 避免新增目标注解时漏掉 `#[Config]` 冲突检测。
     * @var array<class-string,string>
     */
    private const AUTOWIRE_TARGET_ATTRIBUTES=array(
        AutowireProperty::class=>'#[AutowireProperty]',
        AutowireSetter::class=>'#[AutowireSetter]',
        AutowireMethod::class=>'#[AutowireMethod]',
        AutowireLifecycle::class=>'#[AutowireLifecycle]'
    );

    /**
     * 反射缓存
     * @var ReflectionCache
     */
    private ReflectionCache $reflections;

    /**
     * 真实类名解析回调(签名: string $class => string)
     * @var Closure|null
     */
    private ?Closure $class_resolver=null;

    /**
     * 实例解析回调(签名: string $class => object)
     * @var Closure|null
     */
    private ?Closure $instance_resolver=null;

    /**
     * 参数解析器(生命周期方法的形参按类型注入时复用同一套合并规则)
     * @var ArgumentResolverInterface|null
     */
    private ?ArgumentResolverInterface $arguments=null;

    /**
     * 代理创建器回调(签名: string $class, array $args => DynamicProxy)
     * @var Closure|null
     */
    private ?Closure $proxy_resolver=null;

    /**
     * 延迟注入任务队列
     *
     * 任务只在最外层容器入口成功构建后执行; 任务内可以继续追加任务。
     * @var array<int, Closure>
     */
    private array $deferred=array();

    /**
     * 是否正在刷新延迟队列(防止任务内的公共容器调用再次刷新)
     * @var bool
     */
    private bool $flushing_deferred=false;

    /**
     * 构造方法
     *
     * @access public
     * @param ReflectionCache|null $reflections 反射缓存(默认新建)
     */
    public function __construct(?ReflectionCache $reflections=null) {
        $this->reflections=$reflections??new ReflectionCache();
    }

    /**
     * 设置真实类名解析回调
     *
     * @access public
     * @param callable $resolver 回调(接收类名, 返回解析别名与绑定后的类名)
     * @return void
     */
    public function setClassResolver(callable $resolver): void {
        $this->class_resolver=$resolver(...);
    }

    /**
     * 设置实例解析回调
     *
     * @access public
     * @param callable $resolver 回调(接收类名, 返回可注入的实例)
     * @return void
     */
    public function setInstanceResolver(callable $resolver): void {
        $this->instance_resolver=$resolver(...);
    }

    /**
     * 设置参数解析器
     *
     * - 生命周期方法的形参"按类型注入"时复用参数解析器, 与构造函数注入保持同一套规则
     * - 必须在调用 `autowire()` 之前回填
     *
     * @access public
     * @param ArgumentResolverInterface $arguments 参数解析器
     * @return void
     */
    public function setArgumentResolver(ArgumentResolverInterface $arguments): void {
        $this->arguments=$arguments;
    }

    /**
     * 刷新延迟注入队列
     *
     * - 由容器在最外层构建成功、根对象登记之后调用
     * - 任务执行期间追加的新任务会在同一轮继续处理
     * - 任务抛异常时清空剩余队列, 避免失败请求的延迟任务污染后续请求
     *
     * @access public
     * @return void
     */
    public function flushDeferred(): void {
        if($this->flushing_deferred)
            return;
        $this->flushing_deferred=true;
        try {
            $index=0;
            while(array_key_exists($index,$this->deferred)) {
                $task=$this->deferred[$index];
                unset($this->deferred[$index]);
                $index++;
                $task();
            }
        } finally {
            $this->deferred=array();
            $this->flushing_deferred=false;
        }
    }

    /**
     * 清空尚未执行的延迟注入任务
     *
     * 根对象构建失败时由容器调用, 避免把半成品对象的任务留到后续请求。
     *
     * @access public
     * @return void
     */
    public function clearDeferred(): void {
        $this->deferred=array();
    }

    /**
     * 追加一个延迟注入任务
     *
     * @access private
     * @param Closure $task 任务
     * @return void
     */
    private function defer(Closure $task): void {
        $this->deferred[]=$task;
    }

    /**
     * 查找目标上的自动注入注解
     *
     * @access private
     * @param ReflectionProperty|ReflectionMethod $target 属性或方法
     * @return string|null 注解名; 没有时返回 null
     */
    private function findAutowireTargetAttribute(
        ReflectionProperty|ReflectionMethod $target
    ): ?string {
        foreach(self::AUTOWIRE_TARGET_ATTRIBUTES as $attribute=>$label)
            if($target->getAttributes($attribute)!==array())
                return $label;
        return null;
    }

    /**
     * 解析真实类名(别名与绑定)
     *
     * @access private
     * @param string $class 类名或别名
     * @return string
     */
    private function resolveClass(string $class): string {
        if($this->class_resolver===null)
            return $class;
        return ($this->class_resolver)($class);
    }

    /**
     * 按类名装配出实例(由容器回填的回调完成)
     *
     * - `$is_force` 与 `$flags` 必须一并透传: `$flags` 是**引用**的构建栈, 用于阻断依赖注入死循环
     *   (丢了这个参数, 相互依赖的属性注入会无限递归)
     *
     * @access private
     * @param string $class 类名或别名
     * @param bool $is_force 是否强制新建
     * @param array<mixed> $flags 构建标识(引用传递)
     * @return object
     */
    private function resolveInstance(string $class,bool $is_force=false,array &$flags=array()): object {
        if($this->instance_resolver!==null)
            return ($this->instance_resolver)($class,$is_force,$flags);
        // 未回填回调时退化为无参实例化(与"容器未就绪"的旧行为一致)
        return $this->reflections->getClass($class)->newInstance();
    }

    /**
     * 自动注入
     * 
     * @access protected
     * @param object $instance 需要注入属性的对象实例
     * @param array<mixed> $flags 标识(请不要传入该参数,该参数主要用于防止依赖注入死循环)
     * @throws Exception
     * @return void
     */
    public function autowire(object $instance,array &$flags=[]): void {
        // 获取对象的反射
        $ref=$this->reflections->getObject($instance);
        // 获取类的所有属性
        $properties=$ref->getProperties();
        // 自动注入属性
        $this->autowireProperty($properties,$instance,$ref,$flags);
        // 获取类的所有方法
        $methods=$ref->getMethods();
        // 自动注入Setter方法
        $this->autowireSetter($methods,$instance,$ref,$flags);
        // 自动注入生命周期方法(#[AutowireLifecycle])与单依赖方法(#[AutowireMethod])
        $this->autowireMethod($methods,$instance,$ref,$flags);
    }

    /**
     * 将反射类型转为类型数组
     * 
     * @access protected
     * @param ?ReflectionType $type 反射类型实例
     * @param bool $allow_builtin 是否允许返回内置类型 
     * @return string[] 类型名数组
     */
    private function reflectionTypeToArray(
        ?ReflectionType $type,
        bool $allow_builtin=true
    ): array {
        if($type instanceof ReflectionNamedType) {
            $name=$type->getName();
            if(!$allow_builtin&&$type->isBuiltin())
                return [];
            $types=[$name];
            if($allow_builtin&&$type->allowsNull()&&$name!=='null') {
                $types[]='null';
            }
            return $types;
        }
        if($type instanceof ReflectionUnionType) {
            $types=[];
            foreach($type->getTypes() as $t) {
                $types=array_merge($types,$this->reflectionTypeToArray($t,$allow_builtin));
            }
            // 去重
            return array_values(array_unique($types));
        }
        // 其他
        return [];
    }

    /**
     * 类型是否包含交叉类型(含 DNF 分支)
     *
     * 交叉类型当前未实现 AND 语义; 代理注入至少不能把它误当成"无类型"而静默放行。
     *
     * @access private
     * @param ReflectionType|null $type 反射类型
     * @return bool
     */
    private function hasIntersectionType(?ReflectionType $type): bool {
        if($type instanceof ReflectionIntersectionType)
            return true;
        if($type instanceof ReflectionUnionType) {
            foreach($type->getTypes() as $t)
                if($this->hasIntersectionType($t))
                    return true;
        }
        return false;
    }

    /**
     * 代理声明类型是否兼容
     *
     * - 未声明类型: 兼容
     * - `DynamicProxy` / `mixed` / `object`: 兼容
     * - 联合类型: 必须包含 `DynamicProxy`
     * - 交叉类型 / DNF: 当前不支持
     *
     * @access private
     * @param ReflectionType|null $type 反射类型
     * @param string[] $type_array 已解析的非内置类型
     * @return bool
     */
    private function isProxyTypeCompatible(?ReflectionType $type,array $type_array): bool {
        if($type===null)
            return true;
        if($this->hasIntersectionType($type))
            return false;
        if($type instanceof ReflectionNamedType) {
            if($type->isBuiltin())
                return in_array($type->getName(),array('mixed','object'),true);
            return $type->getName()===DynamicProxy::class
                ||in_array(DynamicProxy::class,$type_array,true);
        }
        if($type instanceof ReflectionUnionType)
            return in_array(DynamicProxy::class,$type_array,true);
        return false;
    }

    /**
     * 通过类型和注解构造一个自动注入的参数
     *
     * - `Normal`: 直接解析真实对象
     * - `Proxy`: 严格校验代理类型; 失败直接抛异常, 不回落普通注入
     * - `Deferred`: 不在这里解析, 由调用方登记延迟任务
     *
     * @access protected
     * @param ReflectionProperty|ReflectionParameter $parameter 反射参数实例
     * @param ReflectionClass $ref 需要注入属性的反射类实例
     * @param ?string $explicit_class 显式标注的类名
     * @param AutowireMode $mode 注入模式
     * @param array<mixed> $flags 标识(请不要传入该参数,该参数主要用于防止依赖注入死循环)
     * @throws AutowireException
     * @return object
     */
    private function getReflectionPropertyValue(
        ReflectionProperty|ReflectionParameter $parameter,
        ReflectionClass $ref,
        ?string $explicit_class=null,
        AutowireMode $mode=AutowireMode::Normal,
        array &$flags=[]
    ): object {
        try {
            // 获取属性的类型
            $type=$parameter->getType();
            $type_array=$this->reflectionTypeToArray($type,false);
            if($mode===AutowireMode::Deferred)
                throw new AutowireException(
                    'Deferred injection for parameter "'.$parameter->getName().'" of class "'.
                    $ref->getName().'" must be queued by the autowire layer.',
                );
            if($mode===AutowireMode::Proxy) {
                // 代理必须有显式目标类; 不能从 DynamicProxy 类型或属性名猜目标
                if($explicit_class===null)
                    throw new AutowireException(
                        'Dynamic proxy injection for parameter "'.$parameter->getName().'" of class "'.
                        $ref->getName().'" requires an explicit class name.',
                    );
                // 交叉类型当前没有 AND 解析, 不能把它当"无类型"静默放行
                if($this->hasIntersectionType($type))
                    throw new AutowireException(
                        'Dynamic proxy injection for parameter "'.$parameter->getName().'" of class "'.
                        $ref->getName().'" does not support intersection types.',
                    );
                // 只允许未声明类型、DynamicProxy / mixed / object 类型, 或包含 DynamicProxy 的联合类型
                if(!$this->isProxyTypeCompatible($type,$type_array))
                    throw new AutowireException(
                        'Dynamic proxy injection for parameter "'.$parameter->getName().'" of class "'.
                        $ref->getName().'" is not compatible with type "'.(string)$type.'"; '.
                        'the type must be DynamicProxy, mixed, object, untyped, '.
                        'or a union containing DynamicProxy.',
                    );
                $explicit_class=$this->resolveClass($explicit_class);
                return $this->proxy($explicit_class);
            }
            if($explicit_class!==null) {
                $explicit_class=$this->resolveClass($explicit_class);
                // 判断是否是当前类的子类(第一个参数是"待检查的实现类", 第二个才是声明的类型)
                if(!empty($type_array)) {
                    foreach($type_array as $t) {
                        if(is_a($explicit_class,$t,true)) {
                            // 直接注入并跳出循环
                            return $this->resolveInstance($explicit_class,false,$flags);
                        }
                    }
                    // 显式指定的类与声明类型不兼容: 不能静默改注入另一个实现
                    throw new AutowireException(
                        'Explicit class "'.$explicit_class.'" is not compatible with parameter "'.
                        $parameter->getName().'" of class "'.$ref->getName().'".',
                    );
                }
                // 未声明类型: 使用显式类
                return $this->resolveInstance($explicit_class,false,$flags);
            }
            // 判断允许的类型是否为空
            if(empty($type_array))
                throw new AutowireException(
                'Parameter "'.$parameter->getName().'" of class "'.$ref->getName().
                '" has no type declaration and no class name is specified.',
            );
            // 如果没有指定类名,则根据类型注入
            $class_name=$this->resolveClass($type_array[0]);
            return $this->resolveInstance($class_name,false,$flags);
        } catch(Exception $e) {
            throw new AutowireException(
                $e->getMessage(),
                0,
                [
                    'property'=>$parameter->getName(),
                    'class'=>$ref->getName()
                ]
            );
        }
    }

    /**
     * 登记属性延迟注入任务
     *
     * @access private
     * @param ReflectionProperty $property 属性
     * @param object $instance 目标对象
     * @param ReflectionClass $ref 目标对象的反射类
     * @param ?string $explicit_class 显式类名
     * @return void
     */
    private function deferProperty(
        ReflectionProperty $property,
        object $instance,
        ReflectionClass $ref,
        ?string $explicit_class
    ): void {
        $this->defer(function() use($property,$instance,$ref,$explicit_class): void {
            try {
                $flags=array();
                $value=$this->getReflectionPropertyValue(
                    $property,
                    $ref,
                    $explicit_class,
                    AutowireMode::Normal,
                    $flags
                );
                $property->setAccessible(true);
                $property->setValue($instance,$value);
            } catch(\Throwable $e) {
                throw new AutowireException(
                    'Deferred injection failed for property "'.$property->getName().'" of class "'.
                    $ref->getName().'": '.$e->getMessage(),
                    0,
                    array(
                        'property'=>$property->getName(),
                        'class'=>$ref->getName(),
                        'mode'=>AutowireMode::Deferred->value
                    )
                );
            }
        });
    }

    /**
     * 登记 Setter 延迟注入任务
     *
     * @access private
     * @param ReflectionMethod $method Setter 方法
     * @param object $instance 目标对象
     * @param ReflectionClass $ref 目标对象的反射类
     * @param ReflectionParameter $parameter Setter 的唯一形参
     * @param ?string $explicit_class 显式类名
     * @return void
     */
    private function deferSetter(
        ReflectionMethod $method,
        object $instance,
        ReflectionClass $ref,
        ReflectionParameter $parameter,
        ?string $explicit_class
    ): void {
        $this->defer(function() use($method,$instance,$ref,$parameter,$explicit_class): void {
            try {
                $flags=array();
                $value=$this->getReflectionPropertyValue(
                    $parameter,
                    $ref,
                    $explicit_class,
                    AutowireMode::Normal,
                    $flags
                );
                $method->setAccessible(true);
                $method->invoke($instance,$value);
            } catch(\Throwable $e) {
                throw new AutowireException(
                    'Deferred injection failed for setter "'.$method->getName().'" of class "'.
                    $ref->getName().'": '.$e->getMessage(),
                    0,
                    array(
                        'method'=>$method->getName(),
                        'class'=>$ref->getName(),
                        'mode'=>AutowireMode::Deferred->value
                    )
                );
            }
        });
    }

    /**
     * 登记单依赖方法延迟注入任务
     *
     * @access private
     * @param ReflectionMethod $method 目标方法
     * @param object $instance 目标对象
     * @param ReflectionClass $ref 目标对象的反射类
     * @param ReflectionParameter $parameter 方法的唯一形参
     * @param ?string $explicit_class 显式类名
     * @return void
     */
    private function deferMethod(
        ReflectionMethod $method,
        object $instance,
        ReflectionClass $ref,
        ReflectionParameter $parameter,
        ?string $explicit_class
    ): void {
        $this->defer(function() use($method,$instance,$ref,$parameter,$explicit_class): void {
            try {
                $flags=array();
                $value=$this->getReflectionPropertyValue(
                    $parameter,
                    $ref,
                    $explicit_class,
                    AutowireMode::Normal,
                    $flags
                );
                $method->setAccessible(true);
                $method->invokeArgs($instance,array($value));
            } catch(\Throwable $e) {
                throw new AutowireException(
                    'Deferred injection failed for method "'.$method->getName().'" of class "'.
                    $ref->getName().'": '.$e->getMessage(),
                    0,
                    array(
                        'method'=>$method->getName(),
                        'class'=>$ref->getName(),
                        'mode'=>AutowireMode::Deferred->value
                    )
                );
            }
        });
    }

    /**
     * 自动注入属性
     * 
     * @access protected
     * @param ReflectionProperty[] $properties 需要注入属性的反射实例数组
     * @param object $instance 需要注入属性的对象实例
     * @param ReflectionClass $ref 需要注入属性的反射类实例
     * @param array<mixed> $flags 标识(请不要传入该参数,该参数主要用于防止依赖注入死循环)
     * @throws AutowireException
     * @return void
     */
    private function autowireProperty(
        array $properties,
        object $instance,
        ReflectionClass $ref,
        array &$flags=[]
    ): void {
        foreach($properties as $property) {
            // 配置项注入: #[Config('键')] 标在属性上
            $config=$this->arguments?->configAttribute($property);
            if($config!==null) {
                // 同一属性再标自动注入目标注解属自相矛盾(一个属性不能既是配置值又是注入的服务对象)
                $conflict=$this->findAutowireTargetAttribute($property);
                if($conflict!==null)
                    throw new AutowireException(
                        'Property "'.$property->getName().'" of class "'.$ref->getName().
                        '" cannot be annotated with both #[Config] and '.$conflict.'.',
                    );
                $types=$this->arguments->getStandardTypes($this->reflectionTypeToArray($property->getType(),false));
                // 键缺失时的取值顺序: 注解 default: > 属性自身默认值 > null
                $fallback=$property->hasDefaultValue()?$property->getDefaultValue():null;
                $property->setAccessible(true);
                $property->setValue($instance,$this->arguments->configValue($config,$types,$fallback));
                continue;
            }
            // 获取属性是否有 AutowireProperty 标签
            $attributes=$property->getAttributes(AutowireProperty::class);
            if(empty($attributes))
                continue;
            // 获取 AutowireProperty 实例
            $autowire_attr=$attributes[0]->newInstance();
            // 获取需要注入的对象
            $explicit_class=$autowire_attr->getName();
            $mode=$autowire_attr->getMode();
            if($mode===AutowireMode::Deferred) {
                $this->deferProperty($property,$instance,$ref,$explicit_class);
                continue;
            }
            $make_object=$this->getReflectionPropertyValue(
                $property,
                $ref,
                $explicit_class,
                $mode,
                $flags
            );
            // 注入对象
            $property->setAccessible(true);
            $property->setValue($instance, $make_object);
        }
    }

    /**
     * 自动Setter注入
     * 
     * @access protected
     * @param ReflectionMethod[] $methods 需要注入属性的反射实例数组
     * @param object $instance 需要注入属性的对象实例
     * @param ReflectionClass $ref 需要注入属性的反射类实例
     * @param array<mixed> $flags 标识(请不要传入该参数,该参数主要用于防止依赖注入死循环)
     * @throws AutowireException
     * @return void
     */
    private function autowireSetter(
        array $methods,
        object $instance,
        ReflectionClass $ref,
        array &$flags=[]
    ): void {
        $method=null;
        try{
            foreach($methods as $method) {
                // 配置项注入: #[Config('键')] 标在 Setter 方法上, 方法唯一的形参收到配置值
                $config=$this->arguments?->configAttribute($method);
                if($config!==null) {
                    // 一个方法上不能同时挂 #[Config] 与自动注入目标注解:
                    // 前者是"注入配置值的 setter", 后者是"注入服务对象的 setter / 生命周期钩子", 语义冲突
                    $conflict=$this->findAutowireTargetAttribute($method);
                    if($conflict!==null)
                        throw new AutowireException(
                            'Method "'.$method->getName().'" of class "'.$ref->getName().
                            '" cannot be annotated with both #[Config] and '.$conflict.'.',
                        );
                    $params=$method->getParameters();
                    if(count($params)!==1)
                        throw new AutowireException(
                            'Method "'.$method->getName().'" of class "'.$ref->getName().
                            '" with #[Config] must have exactly one parameter.',
                        );
                    $param=$params[0];
                    $types=$this->arguments->getStandardTypes($this->reflectionTypeToArray($param->getType(),false));
                    $method->setAccessible(true);
                    $method->invoke($instance,$this->arguments->configValue(
                        $config,
                        $types,
                        $param->isDefaultValueAvailable()?$param->getDefaultValue():null
                    ));
                    continue;
                }
                // 获取属性是否有 AutowireSetter 标签
                $attributes=$method->getAttributes(AutowireSetter::class);
                if(empty($attributes))
                    continue;
                // 同一方法既当 Setter 又当生命周期钩子会被调用两次, 属自相矛盾, 直接报错
                foreach(array(AutowireMethod::class=>'#[AutowireMethod]',AutowireLifecycle::class=>'#[AutowireLifecycle]') as $other=>$other_name)
                    if($method->getAttributes($other)!==array())
                        throw new AutowireException(
                            'Method "'.$method->getName().'" of class "'.$ref->getName().
                            '" cannot be annotated with both #[AutowireSetter] and '.$other_name.'.',
                        );
                // 判断参数是否为一个类名或接口名
                $params=$method->getParameters();
                if(count($params)!==1)
                    throw new AutowireException(
                        'Setter method "'.$method->getName().'" of class "'.$ref->getName().
                        '" must have exactly one parameter.',
                    );
                $param=$params[0];
                // 形参上的 #[Config] 在"AutowireSetter 注入服务对象"这条路径不会被应用(静默失效), 直接报错
                if($this->arguments?->configAttribute($param)!==null)
                    throw new AutowireException(
                        'Parameter "'.$param->getName().'" of method "'.$method->getName().'" of class "'.$ref->getName().
                        '" is annotated with #[Config], which is not applied here: annotate the method itself with #[Config] to inject a config value.',
                    );
                // 获取 AutowireSetter 实例
                $autowire_attr=$attributes[0]->newInstance();
                // 获取需要注入的对象
                $explicit_class=$autowire_attr->getName();
                $mode=$autowire_attr->getMode();
                if($mode===AutowireMode::Deferred) {
                    $this->deferSetter($method,$instance,$ref,$param,$explicit_class);
                    continue;
                }
                $make_object=$this->getReflectionPropertyValue(
                    $param,
                    $ref,
                    $explicit_class,
                    $mode,
                    $flags
                );
                // 注入对象
                $method->setAccessible(true);
                $method->invoke($instance, $make_object);
            }
        } catch(Exception $e) {
            throw new AutowireException(
                $e->getMessage(),
                0,
                [
                    'property'=>$method?->getName(),
                    'class'=>$ref->getName()
                ]
            );
        }
    }

    /**
     * 自动方法注入(生命周期钩子与单依赖方法)
     *
     * - `#[AutowireLifecycle]`: 全部参数按类型注入, 与构造函数注入一致
     * - `#[AutowireMethod(类名)]`: 单参数方法, 仅注入显式依赖, 支持 Proxy / Deferred
     * - 无 `name` 的 `#[AutowireMethod]` 是旧生命周期写法, 保留一个版本兼容;
     *   该写法禁止 Proxy / Deferred, 新代码请使用 `#[AutowireLifecycle]`
     *
     * @access protected
     * @param ReflectionMethod[] $methods 需要注入方法的反射实例数组
     * @param object $instance 需要注入方法的对象实例
     * @param ReflectionClass $ref 需要注入方法的反射类实例
     * @param array<mixed> $flags 标识(请不要传入该参数,该参数主要用于防止依赖注入死循环)
     * @throws AutowireException
     * @return void
     */
    private function autowireMethod(
        array $methods,
        object $instance,
        ReflectionClass $ref,
        array &$flags=[]
    ): void {
        $method=null;
        try {
            foreach($methods as $method) {
                $lifecycle_attributes=$method->getAttributes(AutowireLifecycle::class);
                $attributes=$method->getAttributes(AutowireMethod::class);
                // 生命周期钩子与单依赖方法不能落在同一个方法上, 否则语义与调用参数都不确定
                if($lifecycle_attributes!==array()&&$attributes!==array())
                    throw new AutowireException(
                        'Method "'.$method->getName().'" of class "'.$ref->getName().
                        '" cannot be annotated with both #[AutowireLifecycle] and #[AutowireMethod].',
                    );
                if($lifecycle_attributes!==array()) {
                    // 防御性检查: autowireSetter() 已经拒绝 Setter + 生命周期, 这里防止绕过入口
                    if($method->getAttributes(AutowireSetter::class)!==array())
                        throw new AutowireException(
                            'Method "'.$method->getName().'" of class "'.$ref->getName().
                            '" cannot be annotated with both #[AutowireSetter] and #[AutowireLifecycle].',
                        );
                    // 生命周期方法: 全部参数按类型注入
                    $args=$this->arguments->merge($method->getParameters(),array(),true);
                } else {
                    if(empty($attributes))
                        continue;
                    $autowire_attr=$attributes[0]->newInstance();
                    $mode=$autowire_attr->getMode();
                    $params=$method->getParameters();
                    // 显式指定 name: 仅支持单参数方法(与 Setter 注入一致, 支持 Proxy / Deferred)
                    if($autowire_attr->getName()!==null) {
                        if(count($params)!==1)
                            throw new AutowireException(
                                'Method "'.$method->getName().'" of class "'.$ref->getName().
                                '" with explicit name must have exactly one parameter.',
                            );
                        // 形参上的 #[Config] 在"显式单依赖方法注入"路径不会被应用(静默失效), 直接报错
                        if($this->arguments?->configAttribute($params[0])!==null)
                            throw new AutowireException(
                                'Parameter "'.$params[0]->getName().'" of method "'.$method->getName().
                                '" of class "'.$ref->getName().
                                '" is annotated with #[Config], which is not applied here: '.
                                '#[AutowireMethod] injects an explicit service; use #[AutowireLifecycle] for config-driven lifecycle parameters.',
                            );
                        if($mode===AutowireMode::Deferred) {
                            $this->deferMethod($method,$instance,$ref,$params[0],$autowire_attr->getName());
                            continue;
                        }
                        $args=array($this->getReflectionPropertyValue(
                            $params[0],
                            $ref,
                            $autowire_attr->getName(),
                            $mode,
                            $flags
                        ));
                    } else {
                        // 旧写法: 未指定 name 时按生命周期方法处理; 新代码请使用 #[AutowireLifecycle]
                        if($mode!==AutowireMode::Normal)
                            throw new AutowireException(
                                'Method "'.$method->getName().'" of class "'.$ref->getName().
                                '" uses #[AutowireMethod] without a name as a legacy lifecycle hook; '.
                                'Proxy / Deferred are not supported here. Use #[AutowireLifecycle] for lifecycle hooks.',
                            );
                        $args=$this->arguments->merge($params,array(),true);
                    }
                }
                // 调用方法
                $method->setAccessible(true);
                $method->invokeArgs($instance,$args);
            }
        } catch(Exception $e) {
            throw new AutowireException(
                $e->getMessage(),
                0,
                [
                    'method'=>$method?->getName(),
                    'class'=>$ref->getName()
                ]
            );
        }
    }

    /**
     * 生成一个类的代理实例
     *
     * - 与 `#[AutowireProperty(类名, mode: AutowireMode::Proxy)]` 等目标注解配套
     * - 声明类型必须是 `DynamicProxy`、`mixed` / `object`、无类型, 或包含 `DynamicProxy` 的联合类型;
     *   具体类型与交叉类型(如 `A&DynamicProxy`)在装配阶段直接抛 `AutowireException`,
     *   不会退化成普通对象注入
     * - 代理通常由**容器**创建(容器会把自身注入代理, 代理因此不再反向依赖静态门面);
     *   未回填创建器时退化为直接 `new`(此时代理在使用前需要自行绑定容器)
     *
     * @access public
     * @template T of object
     * @param class-string<T> $name 类名
     * @param array<mixed> $args 构造函数参数
     * @return DynamicProxy<T>
     * @throws Exception
     */
    public function proxy(string $name,array $args=array()): DynamicProxy {
        if($this->proxy_resolver!==null)
            return ($this->proxy_resolver)($name,$args);
        return new DynamicProxy($name,...$args);
    }

    /**
     * 设置代理创建器
     *
     * - 由容器回填, 使代理拿到容器(与 `setClassResolver()` / `setInstanceResolver()` 同一套路)
     *
     * @access public
     * @param callable $resolver 回调(接收类名与构造参数, 返回代理实例)
     * @return void
     */
    public function setProxyResolver(callable $resolver): void {
        $this->proxy_resolver=$resolver(...);
    }
}
