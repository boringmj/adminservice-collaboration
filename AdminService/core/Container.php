<?php

namespace AdminService;

use ReflectionType;
use ReflectionClass;
use ReflectionMethod;
use ReflectionFunction;

use function array_filter;
use function array_key_exists;
use function array_merge;
use function array_unique;
use function array_values;
use function class_exists;
use function count;
use function explode;
use function gettype;
use function in_array;
use function interface_exists;
use function is_subclass_of;
use function str_replace;
use function str_contains;
use function is_string;
use function is_callable;
use function is_numeric;
use function is_int;
use function is_float;
use function is_bool;
use ReflectionParameter;
use ReflectionNamedType;
use ReflectionUnionType;
// use ReflectionIntersectionType; // PHP 8.0 不支持
use ReflectionProperty;
use ReflectionException;
use AdminService\Exception;
use AdminService\DynamicProxy;
use AdminService\Autowire\AutowireSetter;
use AdminService\Autowire\AutowireProperty;
use AdminService\Autowire\AutowireMethod;
use AdminService\exception\AutowireException;

final class Container implements \base\Container {

    /**
     * 对象实例容器
     * @var array
     */
    private array $container=array();

    /**
     * 未被实例化的类容器
     */
    private array $class_container=array();

    /**
     * 全局数据容器
     */
    private array $data_container=array();

    /**
     * 反射缓存(实例状态)
     * @var ReflectionCache
     */
    private ReflectionCache $reflections;

    /**
     * 参数解析器(参数合并 / 类型校验 / 类型转换 / 类型标准化)
     * @var ArgumentResolver
     */
    private ArgumentResolver $arguments;

    /**
     * 类查找器(可实例化子类 / 实现类)
     * @var ClassFinder
     */
    private ClassFinder $classes;

    /**
     * 构造方法
     *
     * - 三个内核组件都是实例对象; 依赖方向为 容器 → 组件(单向)
     * - 组件需要的"类名解析""按类型取实例"能力由容器以回调回填, 避免组件反向依赖容器
     *
     * @access public
     */
    public function __construct() {
        $this->reflections=new ReflectionCache();
        $this->arguments=new ArgumentResolver($this->reflections);
        $this->classes=new ClassFinder($this->reflections);
        // 类查找器: 别名与绑定解析
        $this->classes->setClassResolver(function(string $class): string {
            return $this->getRealClass($class);
        });
        // 参数解析器: 按类型解析出实例(先找可实例化的类, 再交给容器装配)
        $this->arguments->setInstanceResolver(function(array $types): ?object {
            $real_class=$this->classes->getFirstInstantiableClass($types);
            if($real_class===null)
                return null;
            return $this->make($real_class);
        });
    }

    /**
     * 设置是否允许标量参数静默转换
     *
     * @access public
     * @param bool $enable 是否允许
     * @return void
     */
    public function setParamCast(bool $enable): void {
        $this->arguments->setParamCast($enable);
    }

    /**
     * 获取是否允许标量参数静默转换
     *
     * @access public
     * @return bool
     */
    public function getParamCast(): bool {
        return $this->arguments->getParamCast();
    }

    /**
     * 通过已有对象获取反射对象(会缓存结果)
     * 
     * @access public
     * @param object $object 对象
     * @throws ReflectionException
     * @return ReflectionClass
     */
     public function getReflectionByObject(object $object): ReflectionClass {
        return $this->reflections->getObject($object);
    }

    /**
     * 获取对象(如果不存在则自动实例化,自动实例化的前提是构造函数不含任何参数且在类容器中存在)
     *
     * @access public
     * @template T of object
     * @param class-string<T> $name 对象名（类名）
     * @return T|object 返回指定类的实例
     */
    public function get(string $name): object {
        $name=$this->getRealClass($name);
        if(!isset($this->container[$name])) {
            // 如果不存在则判断是否存在该类
            if(!class_exists($name))
                throw new Exception('Class "'.$name.'" not found.');
            // 如果存在则判断是否可以实例化
            $ref=$this->reflections->getClass($name);
            if(!$ref->isInstantiable())
                throw new Exception('Class "'.$name.'" is not instantiable.');
            // 如果可以实例化则实例化一个新的对象
            $this->container[$name]=$ref->newInstance();
        }
        return $this->container[$name];
    }

    /**
     * 设置或添加对象
     * 
     * @access public
     * @param string $name 对象名
     * @param object $object 对象
     * @return void
     */
    public function set(string $name,object $object): void {
        $name=$this->getRealClass($name);
        $this->container[$name]=$object;
    }

    /**
     * 获取名称在容器中实际的类名(如果不存在则返回原类名)
     * 
     * @access public
     * @param string $name 类名
     * @param bool $recursive 是否递归查找(默认为true)
     * @param int $max_depth 最大递归深度
     * @return string
     */
    public function getRealClass(
        string $name,
        bool $recursive=true,
        int $max_depth=255
        ): string {
        if(isset($this->class_container[$name])) {
            if($max_depth<=0)
                throw new Exception('Maximum recursion depth exceeded while resolving class "'.$name.'"');
            // 判断是否为自身循环
            if($this->class_container[$name]===$name)
                return $name;
            // 判断该类是绑定了其他类
            if($recursive&&isset($this->class_container[$this->class_container[$name]])) {
                $name=$this->getRealClass(
                    $this->class_container[$name],
                    $recursive,
                    $max_depth-1
                );
            } else
                $name=$this->class_container[$name];
        }
        return $name;
    }

    /**
     * 为抽象类或接口绑定实现类(会覆盖已存在的绑定或别名)
     * - 支持子类逐级查找(除非方法特殊说明)
     * - 支持嵌套绑定
     * 
     * @access public
     * @param string $name 别名或抽象类或接口名
     * @param string $class 目标类名
     * @return void
     * @throws Exception
     */
    public function setClass(string $name,string $class): void {
        // 如果类不存在则抛出异常
        if(!class_exists($class))
            throw new Exception('Class "'.$class.'" not found.');
        // 判断新绑定是否会形成循环关系
        if($this->isCircular($name,$class))
            throw new Exception('Circular dependency detected for "'.$name.'" and "'.$class.'"');
        $this->class_container[$name]=$class;
    }

    /**
     * 判断绑定是否会形成循环关系
     * 
     * @access public
     * @param string $abstract 别名或父类类名
     * @param string $concrete 目标类名
     * @return bool
     */
    public function isCircular(string $abstract,string $concrete): bool {
        $visited=[$abstract];
        while(isset($this->class_container[$concrete])) {
            if(in_array($concrete,$visited,true))
                // 检查到循环
                return true;
            $visited[]=$concrete;
            $concrete=$this->class_container[$concrete];
        }
        // 最终还需要是否自循环
        return $concrete===$abstract;
    }

    /**
     * 为抽象类或接口绑定实现类(会覆盖已存在的绑定或别名)
     * - 支持子类逐级查找(除非方法特殊说明)
     * - 支持嵌套绑定
     * 
     * @access public
     * @param string $abstract 别名或抽象类或接口名
     * @param string $concrete 目标类名
     * @return void
     * @throws Exception
     */
    public function bind(string $abstract,string $concrete): void {
        $this->setClass($abstract,$concrete);
    }

    /**
     * 批量设置或添加未被实例化的类
     *
     * @access public
     * @param array<string,string> $classes 类数组
     * @return void
     * @throws Exception
     */
    public function setClassByArray(array $classes): void {
        foreach($classes as $name=>$class)
            $this->setClass($name,$class);
    }

    /**
     * 获取全局数据
     * 
     * @access public
     * @param string $name 数据名
     * @param mixed $default 默认值
     * @return mixed
     */
    public function getData(string $name,mixed $default=null): mixed {
        return $this->data_container[$name]??$default;
    }

    /**
     * 设置或添加全局数据
     * 
     * @access public
     * @param string $name 数据名
     * @param mixed $data 数据
     * @return void
     */
    public function setData(string $name,mixed $data): void {
        $this->data_container[$name]=$data;
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
    protected function autowire(object $instance,array &$flags=[]): void {
        // 获取对象的反射
        $ref=$this->getReflectionByObject($instance);
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
    protected function reflectionTypeToArray(
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
    protected function getReflectionPropertyValue(
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
                $explicit_class=$this->getRealClass($explicit_class);
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
                            return $this->make($explicit_class,false,$flags);
                        }
                    }
                }
                // 判断是否兼容当前类
                if(empty($type_array)&&$proxy===false) {
                    return $this->make($explicit_class,false,$flags);
                }
            }
            // 判断允许的类型是否为空
            if(empty($type_array))
                throw new AutowireException(
                'Parameter "'.$parameter->getName().'" of class "'.$ref->getName().
                '" has no type declaration and no class name is specified.',
            );
            // 如果没有指定类名,则根据类型注入
            $class_name=$this->getRealClass($type_array[0]);
            return $this->make($class_name,false,$flags);
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
    protected function autowireProperty(
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
    protected function autowireSetter(
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
    protected function autowireMethod(
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

    /**
     * 通过自动依赖注入实例化一个对象
     *
     * 注意: 依赖简单支持抽象类和接口,重复依赖可能会抛出找不到对象的异常,
     * 这种情况请先使用App::set(Class::class,new Class())添加到容器中
     *
     * @access public
     * @template T of object
     * @param class-string<T> $name 对象名
     * @param bool $is_force 是否强制实例化(仅对当前对象有效,不会影响依赖)
     * @param array<mixed> $flags 标识(请不要传入该参数,该参数主要用于防止依赖注入死循环)
     * @return T|object
     * @throws Exception|ReflectionException
     */
    public function make(string $name,bool $is_force=false,array &$flags=array()): object {
        $name=$this->getRealClass($name);
        // 如果不强制实例化且容器中存在该对象则直接返回,如果标识重复也会直接返回
        if((!$is_force&&isset($this->container[$name])||in_array($name,$flags)))
            return $this->get($name);
        // 判断是否为接口
        if(interface_exists($name)) {
            // 寻找一个可实例化的子类
            $real_class=$this->classes->getFirstInstantiableClass(array($name));
            if($real_class===null)
                throw new Exception('Class "'.$name.'" is not instantiable.');
            return $this->make($real_class,false,$flags);
        }
        // 判断类或接口是否存在
        if(!class_exists($name))
            throw new Exception('Class "'.$name.'" not found.');
        // 将当前对象添加到标识中
        $flags[]=$name;
        $ref=$this->reflections->getClass($name);
        // 判断自身是否可以被实例化
        if(!$ref->isInstantiable()) {
            // 寻找一个可实例化的子类
            $real_class=$this->classes->getFirstInstantiableClass(array($name));
            if($real_class===null)
                throw new Exception('Class "'.$name.'" is not instantiable.');
            return $this->make($real_class,false,$flags);
        }
        $constructor=$ref->getConstructor();
        if($constructor!==null) {
            $params=$constructor->getParameters();
            $args=array();
            foreach($params as $param) {
                $type=$param->getType();
                $type=(string)$type;
                // 将类型分割为数组
                $types=explode('|',$type);
                $types=$this->arguments->getStandardTypes($types);
                // 获取第一个可实例化的类
                $real_class=$this->classes->getFirstInstantiableClass($types);
                if($real_class!==null) {
                    // 递归实例化依赖
                    $args[]=$this->make($real_class,false,$flags);
                    continue;
                }
                else if($param->isDefaultValueAvailable())
                    $args[]=$param->getDefaultValue();
                else if($param->allowsNull())
                    $args[]=null;
                else
                    throw new Exception('Parameter "'.$param->getName().'" of "'.$name.'" constructor is not valid.',0,array(
                        'class'=>$name,
                        'parameter'=>$param->getName()
                    ));
            }
            // 传入构造函数参数实例化一个新的对象
            $object=$ref->newInstanceArgs($args);
        } else
            // 如果没有构造函数则直接实例化一个新的对象
            $object=$ref->newInstance();
        // 自动注入属性
        $this->autowire($object,$flags);
        // 将对象添加到容器中
        $this->set($name,$object);
        // 移出标识中的当前对象
        array_pop($flags);
        return $object;
    }

    /**
     * 实例化一个新对象(不添加到实例容器中)
     * 
     * @access public
     * @template T of object
     * @param class-string<T> $__name 对象名
     * @param mixed ...$args 构造函数参数($args中不允许传入“__name”参数)
     * @return T|object
     * @throws Exception|ReflectionException
     */
    public function new(string $__name,...$args): object {
        // 获取真实类名
        $__name=$this->getRealClass($__name);
        // 判断类或接口是否存在
        if(!class_exists($__name)&&!interface_exists($__name))
            throw new Exception('Class "'.$__name.'" not found.');
        $ref=$this->reflections->getClass($__name);
        // 判断是否可以被实例化,如果不能则尝试寻找一个可实例化的子类
        if(!$ref->isInstantiable()) {
            $real_class=$this->classes->getFirstInstantiableClass(array($__name));
            if($real_class===null)
                throw new Exception('Class "'.$__name.'" is not instantiable.');
            return $this->new($real_class,...$args);
        }
        // 获取构造函数的参数
        $constructor=$ref->getConstructor();
        if($constructor===null) {
            // 如果构造函数不存在则直接实例化一个对象
            return $ref->newInstance();
        }
        $params=$constructor->getParameters();
        $args_temp=$this->arguments->merge($params,$args);
        // 直接返回对象,不添加到父容器中
        return $ref->newInstanceArgs($args_temp);
    }

    /**
     * 执行类或对象的方法
     *
     * @access public
     * @param object|string $object 对象或者类名
     * @param string $method 方法名
     * @param array<mixed> $args 方法参数(如果为关系型数组,则会将key作为参数名,value作为参数值,如果索引数组,则会逐一赋值,没有赋值的参数会使用默认值)
     * @return mixed
     * @throws Exception|ReflectionException
     */
    public function exec_class_function(object|string $object,string $method,array $args=array()): mixed {
        // 判断是否为类名
        if(is_string($object)) {
            // 如果是类名则通过自动依赖注入实例化一个对象
            $object=$this->make($object);
        }
        // 参数合并与调用交给参数解析器
        return $this->arguments->call($object,$method,$args);
    }

    /**
     * 执行函数
     *
     * @access public
     * @param string|array|callable $function 函数名(支持数组形式的类方法调用和闭包)
     * @param array<mixed> $args 函数参数(如果为关系型数组,则会将key作为参数名,value作为参数值,如果索引数组,则会逐一赋值,没有赋值的参数会使用默认值)
     * @return mixed
     * @throws Exception|ReflectionException
     */
    public function exec_function(string|array|callable $function,array $args=array()): mixed {
        if(is_array($function)) {
            // 类方法调用
            [$classOrObj,$method]=$function;
            return $this->exec_class_function($classOrObj,$method,$args);
        }
        // 参数合并与调用交给参数解析器
        return $this->arguments->callFunction($function,$args);
    }

}
