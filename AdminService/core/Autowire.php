<?php

namespace AdminService;

use ReflectionType;
use ReflectionClass;
use ReflectionParameter;
use ReflectionNamedType;
use ReflectionUnionType;
// use ReflectionIntersectionType; // PHP 8.0 不支持
use ReflectionProperty;
use AdminService\DynamicProxy;
use AdminService\Autowire\AutowireSetter;
use AdminService\Autowire\AutowireProperty;
use AdminService\Autowire\AutowireMethod;
use AdminService\exception\AutowireException;
use Closure;

use function array_merge;
use function array_values;
use function count;
use function in_array;

/**
 * 自动装配器
 *
 * - 三路装配: 属性(`#[AutowireProperty]`)、Setter(`#[AutowireSetter]`)、生命周期方法(`#[AutowireMethod]`)
 * - 含显式类名判定与动态代理注入(`proxy()`)
 * - 依赖方向: 本类**不依赖容器**; "类名解析(`getRealClass`)"与"按类名装配实例(`make`)"由容器以回调回填,
 *   于是 容器 → 装配器 是单向的
 * - 构建标识 `$flags` 仍以引用传递(防止依赖注入死循环), 与调用方共用同一份栈
 *
 * @access public
 * @package AdminService
 * @version 1.0.0
 */
final class Autowire {

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
     * @var ArgumentResolver|null
     */
    private ?ArgumentResolver $arguments=null;

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
     * @param ArgumentResolver $arguments 参数解析器
     * @return void
     */
    public function setArgumentResolver(ArgumentResolver $arguments): void {
        $this->arguments=$arguments;
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
        // 自动注入生命周期方法(#[AutowireMethod])
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
     * 通过类型和注解构造一个自动注入的参数
     * 
     * @access protected
     * @param ReflectionProperty|ReflectionParameter $parameter 反射参数实例
     * @param ReflectionClass $ref 需要注入属性的反射类实例
     * @param ?string $explicit_class 显式标注的类名
     * @param bool $proxy 是否注入动态代理类
     * @param array<mixed> $flags 标识(请不要传入该参数,该参数主要用于防止依赖注入死循环)
     * @throws AutowireException
     * @return object
     */
    private function getReflectionPropertyValue(
        ReflectionProperty|ReflectionParameter $parameter,
        ReflectionClass $ref,
        ?string $explicit_class=null,
        bool $proxy=false,
        array &$flags=[]
    ): object {
        try {
            // 获取属性的类型
            $type=$parameter->getType();
            $type_array=$this->reflectionTypeToArray($type,false);
            if($explicit_class!==null) {
                $explicit_class=$this->resolveClass($explicit_class);
                // 判断是否兼容代理类
                if($proxy) {
                    if(in_array(DynamicProxy::class,$type_array)||empty($type_array)) {
                        return $this->proxy($explicit_class);
                    }
                }
                // 判断是否是当前类的子类
                if(!empty($type_array)&&$proxy===false) {
                    foreach($type_array as $t) {
                        if(is_a($t,$explicit_class,true)) {
                            // 直接注入并跳出循环
                            return $this->resolveInstance($explicit_class,false,$flags);
                        }
                    }
                }
                // 判断是否兼容当前类
                if(empty($type_array)&&$proxy===false) {
                    return $this->resolveInstance($explicit_class,false,$flags);
                }
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
            // 获取属性是否有 AutowireProperty 标签
            $attributes=$property->getAttributes(AutowireProperty::class);
            if(empty($attributes))
                continue;
            // 获取 AutowireProperty 实例
            $autowire_attr=$attributes[0]->newInstance();
            // 获取需要注入的对象
            $explicit_class=$autowire_attr->getName();
            $make_object=$this->getReflectionPropertyValue(
                $property,
                $ref,
                $explicit_class,
                $autowire_attr->getProxy(),
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
                // 获取属性是否有 AutowireSetter 标签
                $attributes=$method->getAttributes(AutowireSetter::class);
                if(empty($attributes))
                    continue;
                // 判断参数是否为一个类名或接口名
                $params=$method->getParameters();
                if(count($params)!==1)
                    throw new AutowireException(
                        'Setter method "'.$method->getName().'" of class "'.$ref->getName().
                        '" must have exactly one parameter.',
                    );
                $param=$params[0];
                // 获取 AutowireSetter 实例
                $autowire_attr=$attributes[0]->newInstance();
                // 获取需要注入的对象
                $explicit_class=$autowire_attr->getName();
                $make_object=$this->getReflectionPropertyValue(
                    $param,
                    $ref,
                    $explicit_class,
                    $autowire_attr->getProxy(),
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
     * 自动方法注入(生命周期钩子)
     *
     * - 对标记了 #[AutowireMethod] 的方法, 在构建并注入属性/Setter 后自动调用
     * - 参数按类型自动注入, 与构造函数注入一致(复用 mergeParams: 类型解析 → 默认值 → null → 报错)
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
                // 获取方法是否有 AutowireMethod 标签
                $attributes=$method->getAttributes(AutowireMethod::class);
                if(empty($attributes))
                    continue;
                $autowire_attr=$attributes[0]->newInstance();
                $params=$method->getParameters();
                // 显式指定 name: 仅支持单参数方法(与 Setter 注入一致, 支持 proxy)
                if($autowire_attr->getName()!==null) {
                    if(count($params)!==1)
                        throw new AutowireException(
                            'Method "'.$method->getName().'" of class "'.$ref->getName().
                            '" with explicit name must have exactly one parameter.',
                        );
                    $args=array($this->getReflectionPropertyValue(
                        $params[0],$ref,$autowire_attr->getName(),$autowire_attr->getProxy(),$flags
                    ));
                } else {
                    // 未指定 name: 全部参数按类型注入(与构造函数注入一致)
                    $args=$this->arguments->merge($params,array());
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
     * @access public
     * @template T of object
     * @param class-string<T> $name 类名
     * @param array<mixed> $args 构造函数参数
     * @return DynamicProxy<T>
     * @throws Exception
     */
    public function proxy(string $name,array $args=array()): DynamicProxy {
        return new DynamicProxy($name,...$args);
    }
}
