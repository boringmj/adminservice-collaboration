<?php

namespace base;

use \ReflectionType;
use \ReflectionClass;
use \ReflectionMethod;
use \ReflectionFunction;
use \ReflectionParameter;
use \ReflectionNamedType;
use \ReflectionUnionType;
// use \ReflectionIntersectionType; // PHP 8.0 不支持
use \ReflectionProperty;
use \ReflectionException;
use AdminService\Exception;
use AdminService\DynamicProxy;
use AdminService\Autowire\AutowireSetter;
use AdminService\Autowire\AutowireProperty;
use AdminService\exception\AutowireException;

abstract class Container {

    /**
     * 对象实例容器
     * @var array
     */
    protected static array $container;

    /**
     * 未被实例化的类容器
     */
    protected static array $class_container;

    /**
     * 全局数据容器
     */
    protected static array $data_container;

    /**
     * 缓存的反射类解析对象
     * @var array<string,ReflectionClass>
     */
    protected static array $class_reflection_container;

    /**
     * 缓存的反射方法对象
     * @var array<string,ReflectionMethod>
     */
    protected static array $method_reflection_container;

    /**
     * 缓存的反射函数对象
     * @var array<string,ReflectionFunction>
     */
    protected static array $function_reflection_container;

    /**
     * 初始化
     * 
     * @access public
     * @param array $classes 需要初始化的类
     * @return void
     */
    abstract static public function init(array $classes=array()): void;

    /**
     * 获取反射对象(会缓存结果,不支持别名和绑定)
     * 
     * @access public
     * @param string $name 类名
     * @throws ReflectionException
     * @return ReflectionClass
     */
    static public function getReflection(string $name): ReflectionClass {
        if(!isset(self::$class_reflection_container[$name])) {
            self::$class_reflection_container[$name]=new ReflectionClass($name);
        }
        return self::$class_reflection_container[$name];
    }

    /**
     * 通过已有对象获取反射对象(会缓存结果)
     * 
     * @access public
     * @param object $object 对象
     * @throws ReflectionException
     * @return ReflectionClass
     */
     static public function getReflectionByObject(object $object): ReflectionClass {
        $class=$object::class;
        return self::getReflection($class);
    }

    /**
     * 获取反射方法(会缓存结果)
     * 
     * @access public
     * @param string $class 类名(支持Class::method语法)
     * @param string $method 方法名(可为空，当 $class 使用 Class::method 时)
     * @throws ReflectionException
     * @return ReflectionMethod
     */
    static public function getReflectionMethod(
        string $class,
        ?string $method=null
    ): ReflectionMethod {
        // 如果使用 Class::method 语法
        if($method===null&&str_contains($class,'::'))
            [$class,$method]=explode('::',$class,2);
        if(!$method)
            throw new ReflectionException(
                "Method name not provided for class '{$class}'"
            );
        $key=$class.'::'.$method;
        if(!isset(self::$method_reflection_container[$key])) {
            self::$method_reflection_container[$key]=new ReflectionMethod($class,$method);
        }
        return self::$method_reflection_container[$key];
    }

    /**
     * 通过已有对象获取反射方法(会缓存结果)
     * 
     * @access public
     * @param object $object 对象
     * @param string $method 方法名
     * @throws ReflectionException
     * @return ReflectionMethod
     */
    static public function getReflectionMethodByObject(
        object $object,
        string $method
    ): ReflectionMethod {
        $class=$object::class;
        return self::getReflectionMethod($class,$method);
    }

    /**
     * 获取反射函数对象(会缓存结果,不支持别名和绑定)
     * 
     * @access public
     * @param string $name 函数名
     * @throws ReflectionException
     * @return ReflectionFunction
     */
    static public function getReflectionFunction(string $name): ReflectionFunction {
        if(!isset(self::$function_reflection_container[$name])) {
            self::$function_reflection_container[$name]=new ReflectionFunction($name);
        }
        return self::$function_reflection_container[$name];
    }

    /**
     * 获取对象(如果不存在则自动实例化,自动实例化的前提是构造函数不含任何参数且在类容器中存在)
     *
     * @access public
     * @template T of object
     * @param class-string<T> $__name 对象名（类名）
     * @return T|object 返回指定类的实例
     */
    static public function get(string $name): object {
        $name=self::getRealClass($name);
        if(!isset(self::$container[$name])) {
            // 如果不存在则判断是否存在该类
            if(!class_exists($name))
                throw new Exception('Class "'.$name.'" not found.');
            // 如果存在则判断是否可以实例化
            $ref=self::getReflection($name);
            if(!$ref->isInstantiable())
                throw new Exception('Class "'.$name.'" is not instantiable.');
            // 如果可以实例化则实例化一个新的对象
            self::$container[$name]=$ref->newInstance();
        }
        return self::$container[$name];
    }

    /**
     * 设置或添加对象
     * 
     * @access public
     * @param string $name 对象名
     * @param object $object 对象
     * @return void
     */
    static public function set(string $name,object $object): void {
        $name=self::getRealClass($name);
        self::$container[$name]=$object;
    }

    /**
     * 获取未被实例化的类名称
     * 
     * @access public
     * @param string $name 类名
     */
    static public function getClass(string $name): string {
        $name=self::getRealClass($name);
        // 如果类容器中不存在该类则返回原类名
        if(!isset(self::$class_container[$name]))
            return $name;
        return self::$class_container[$name];
    }

    /**
     * 获取名称在容器中实际的类名(如果不存在则返回原类名)
     * 
     * @access public
     * @param string $name 类名
     * @param bool $recursive 是否递归查找(默认为true)
     * @param bool $max_depth 最大递归深度
     * @return string
     */
    static public function getRealClass(
        string $name,
        bool $recursive=true,
        int $max_depth=255
        ): string {
        if(isset(self::$class_container[$name])) {
            if($max_depth<=0)
                throw new Exception('Maximum recursion depth exceeded while resolving class "'.$name.'"');
            // 判断是否为自身循环
            if(self::$class_container[$name]===$name)
                return $name;
            // 判断该类是绑定了其他类
            if($recursive&&isset(self::$class_container[self::$class_container[$name]])) {
                $name=self::getRealClass(
                    self::$class_container[$name],
                    $recursive,
                    $max_depth-1
                );
            } else
                $name=self::$class_container[$name];
        }
        return $name;
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
    static public function setClass(string $name,string $class): void {
        // 如果类不存在则抛出异常
        if(!class_exists($class))
            throw new Exception('Class "'.$class.'" not found.');
        // 判断新绑定是否会形成循环关系
        if(self::isCircular($name,$class))
            throw new Exception('Circular dependency detected for "'.$name.'" and "'.$class.'"');
        self::$class_container[$name]=$class;
    }

    /**
     * 判断绑定是否会形成循环关系
     * 
     * @access public
     * @param string $abstract 别名或父类类名
     * @param string $concrete 目标类名
     * @return bool
     */
    static public function isCircular(string $abstract,string $concrete): bool {
        $visited=[$abstract];
        while(isset(self::$class_container[$concrete])) {
            if(in_array($concrete,$visited,true))
                // 检查到循环
                return true;
            $visited[]=$concrete;
            $concrete=self::$class_container[$concrete];
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
    static public function bind(string $abstract,string $concrete): void {
        self::setClass($abstract,$concrete);
    }

    /**
     * 批量设置或添加对象
     * 
     * @access public
     * @param array $objects 对象数组
     * @return void
     */
    static public function setByArray(array $objects): void {
        foreach($objects as $name=>$object)
            self::set($name,$object);
    }

    /**
     * 批量设置或添加未被实例化的类
     *
     * @access public
     * @param array $classes 类数组
     * @return void
     * @throws Exception
     */
    static public function setClassByArray(array $classes): void {
        foreach($classes as $name=>$class)
            self::setClass($name,$class);
    }

    /**
     * 获取全局数据
     * 
     * @access public
     * @param string $name 数据名
     * @param mixed $default 默认值
     * @return mixed
     */
    static public function getData(string $name,mixed $default=null): mixed {
        return self::$data_container[$name]??$default;
    }

    /**
     * 设置或添加全局数据
     * 
     * @access public
     * @param string $name 数据名
     * @param mixed $data 数据
     * @return void
     */
    static public function setData(string $name,mixed $data): void {
        self::$data_container[$name]=$data;
    }

    /**
     * 批量设置或添加全局数据
     * 
     * @access public
     * @param array $data 数据数组
     * @return void
     */
    static public function setDataByArray(array $data): void {
        foreach($data as $name=>$value)
            self::setData($name,$value);
    }

    /**
     * 自动注入
     * 
     * @access protected
     * @param object $instance 需要注入属性的对象实例
     * @param array $flags 标识(请不要传入该参数,该参数主要用于防止依赖注入死循环)
     * @throws Exception
     * @return void
     */
    static protected function autowire(object $instance,array &$flags=[]): void {
        // 获取对象的反射
        $ref=self::getReflectionByObject($instance);
        // 获取类的所有属性
        $properties=$ref->getProperties();
        // 自动注入属性
        self::autowireProperty($properties,$instance,$ref,$flags);
        // 获取类的所有方法
        $methods=$ref->getMethods();
        // 自动注入Setter方法
        self::autowireSetter($methods,$instance,$ref,$flags);
    }

    /**
     * 将反射类型转为类型数组
     * 
     * @access protected
     * @param ?ReflectionType $type 反射类型实例
     * @param bool $allow_builtin 是否允许返回内置类型 
     * @return string[] 类型名数组
     */
    static protected function reflectionTypeToArray(
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
                $types=array_merge($types,self::reflectionTypeToArray($t,$allow_builtin));
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
     * @param array $flags 标识(请不要传入该参数,该参数主要用于防止依赖注入死循环)
     * @throws AutowireException
     * @return object
     */
    static protected function getReflectionPropertyValue(
        ReflectionProperty|ReflectionParameter $parameter,
        ReflectionClass $ref,
        ?string $explicit_class=null,
        bool $proxy=false,
        array &$flags=[]
    ): object {
        try {
            // 获取属性的类型
            $type=$parameter->getType();
            $type_array=self::reflectionTypeToArray($type,false);
            if($explicit_class!==null) {
                $explicit_class=self::getRealClass($explicit_class);
                // 判断是否兼容代理类
                if($proxy) {
                    if(in_array(DynamicProxy::class,$type_array)||empty($type_array)) {
                        return self::proxy($explicit_class);
                    }
                }
                // 判断是否是当前类的子类
                if(!empty($type_array)&&$proxy===false) {
                    foreach($type_array as $t) {
                        if(is_a($t,$explicit_class,true)) {
                            // 直接注入并跳出循环
                            return self::make($explicit_class,false,$flags);
                        }
                    }
                }
                // 判断是否兼容当前类
                if(empty($type_array)&&$proxy===false) {
                    return self::make($explicit_class,false,$flags);
                }
            }
            // 判断允许的类型是否为空
            if(empty($type_array))
                throw new AutowireException(
                'Parameter "'.$parameter->getName().'" of class "'.$ref->getName().
                '" has no type declaration and no class name is specified.',
            );
            // 如果没有指定类名,则根据类型注入
            $class_name=self::getRealClass($type_array[0]);
            return self::make($class_name,false,$flags);
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
     * @param array $flags 标识(请不要传入该参数,该参数主要用于防止依赖注入死循环)
     * @throws AutowireException
     * @return void
     */
    static protected function autowireProperty(
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
            $make_object=self::getReflectionPropertyValue(
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
     * @param array $flags 标识(请不要传入该参数,该参数主要用于防止依赖注入死循环)
     * @throws AutowireException
     * @return void
     */
    static protected function autowireSetter(
        array $methods,
        object $instance,
        ReflectionClass $ref,
        array &$flags=[]
    ): void {
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
                $make_object=self::getReflectionPropertyValue(
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
                    'property'=>$method->getName(),
                    'class'=>$ref->getName()
                ]
            );
        }
    }

    // /**
    //  * 自动注入方法(还未完成)
    //  * 
    //  * @access protected
    //  * @param ReflectionMethod[] $methods 需要注入属性的反射实例数组
    //  * @param object $instance 需要注入属性的对象实例
    //  * @param ReflectionClass $ref 需要注入属性的反射类实例
    //  * @param array $flags 标识(请不要传入该参数,该参数主要用于防止依赖注入死循环)
    //  * @throws AutowireException
    //  * @return void
    //  */
    // static protected function autowireMethod(
    //     array $methods,
    //     object $instance,
    //     ReflectionClass $ref,
    //     array &$flags=[]
    // ): void {
    //     try{
    //         foreach($methods as $method) {
    //             // 获取方法是否有 
    //         }
    //     } catch(Exception $e) {
    //         throw new AutowireException(
    //             $e->getMessage(),
    //             0,
    //             [
    //                 'property'=>$method->getName(),
    //                 'class'=>$ref->getName()
    //             ]
    //         );
    //     }
    // }

    /**
     * 生成一个类的代理实例
     *
     * @access public
     * @template T of object
     * @param class-string<T> $name 类名
     * @param array $args 构造函数参数
     * @return DynamicProxy<T>
     * @throws Exception
     */
    static public function proxy(string $name,array $args=array()): DynamicProxy {
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
     * @param array $flags 标识(请不要传入该参数,该参数主要用于防止依赖注入死循环)
     * @return T|object
     * @throws Exception|ReflectionException
     */
    static public function make(string $name,bool $is_force=false,array &$flags=array()): object {
        $name=self::getRealClass($name);
        // 如果不强制实例化且容器中存在该对象则直接返回,如果标识重复也会直接返回
        if((!$is_force&&isset(self::$container[$name])||in_array($name,$flags)))
            return self::get($name);
        // 判断是否为接口
        if(interface_exists($name)) {
            // 寻找一个可实例化的子类
            $real_class=self::getFirstInstantiableClass(array($name));
            if($real_class===null)
                throw new Exception('Class "'.$name.'" is not instantiable.');
            return self::make($real_class,false,$flags);
        }
        // 判断类或接口是否存在
        if(!class_exists($name))
            throw new Exception('Class "'.$name.'" not found.');
        // 将当前对象添加到标识中
        $flags[]=$name;
        $ref=self::getReflection($name);
        // 判断自身是否可以被实例化
        if(!$ref->isInstantiable()) {
            // 寻找一个可实例化的子类
            $real_class=self::getFirstInstantiableClass(array($name));
            if($real_class===null)
                throw new Exception('Class "'.$name.'" is not instantiable.');
            return self::make($real_class,false,$flags);
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
                $types=self::getStandardTypes($types);
                // 获取第一个可实例化的类
                $real_class=self::getFirstInstantiableClass($types);
                if($real_class!==null) {
                    // 递归实例化依赖
                    $args[]=self::make($real_class,false,$flags);
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
        self::autowire($object,$flags);
        // 将对象添加到容器中
        self::set($name,$object);
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
    static public function new(string $__name,...$args): object {
        // 获取真实类名
        $__name=self::getRealClass($__name);
        // 判断类或接口是否存在
        if(!class_exists($__name)&&!interface_exists($__name))
            throw new Exception('Class "'.$__name.'" not found.');
        $ref=self::getReflection($__name);
        // 判断是否可以被实例化,如果不能则尝试寻找一个可实例化的子类
        if(!$ref->isInstantiable()) {
            $real_class=self::getFirstInstantiableClass(array($__name));
            if($real_class===null)
                throw new Exception('Class "'.$__name.'" is not instantiable.');
            return self::new($real_class,...$args);
        }
        // 获取构造函数的参数
        $constructor=$ref->getConstructor();
        if($constructor===null) {
            // 如果构造函数不存在则直接实例化一个对象
            return $ref->newInstance();
        }
        $params=$constructor->getParameters();
        $args_temp=self::mergeParams($params,$args);
        // 直接返回对象,不添加到父容器中
        return $ref->newInstanceArgs($args_temp);
    }

    /**
     * 整理和合并参数
     *
     * @access protected
     * @param ReflectionParameter[] $params 参数
     * @param array $args 参数
     * @return array
     * @throws Exception
     * @throws ReflectionException
     */
    static protected function mergeParams(array $params,array $args): array {
        $params_temp=array();
        $arg_count=0;
        foreach($params as $param) {
            $type=$param->getType();
            $type=(string)$type;
            // 将类型分割为数组
            $types=explode('|',$type);
            $types=self::getStandardTypes($types);
            // 获取参数名
            $name=$param->getName();
            if($param->allowsNull())
                $types[]='NULL';
            $types=array_unique($types);
            // 判断参数类型是否为可变参数
            if($param->isVariadic()) {
                $numeric_args=array_values(array_filter($args,'is_numeric',ARRAY_FILTER_USE_KEY));
                $assoc_args=array_filter($args,'is_string',ARRAY_FILTER_USE_KEY);
                $temp_args=array_merge($numeric_args,$assoc_args);
                // 判断是否有类型限制
                if(count($types)===1&&$types[0]==='') {
                    $params_temp=array_merge($params_temp,$temp_args);
                    break;
                }
                // 判断每个参数是否符合类型限制
                foreach($temp_args as $key=>$value) {
                    if(self::isValidType($value,$types)) {
                        if(is_numeric($key))
                            $params_temp[]=$value;
                        else
                            $params_temp[$key]=$value;
                        unset($args[$key]);
                    } else {
                        throw new Exception('Parameter "'.$param->getName().'" of "'.$param.'" is not valid.',0,array(
                            'class'=>$param,
                            'parameter'=>$param->getName(),
                            'error'=>'The parameter type is not valid.'
                        ));
                    }
                }
                break;
            }
            // 先尝试在参数数组通过参数名查找
            if(array_key_exists($name,$args)&&self::isValidType($args[$name],$types)) {
                $params_temp[]=$args[$name];
                unset($args[$name]);
                continue;
            }
            // 判断是否存在顺位参数
            if(array_key_exists($arg_count,$args)&&self::isValidType($args[$arg_count],$types)) {
                $params_temp[]=$args[$arg_count];
                unset($args[$arg_count]);
                // 顺位参数自增
                $arg_count++;
                continue;
            }
            // 获取第一个可实例化的类
            $real_class=self::getFirstInstantiableClass($types);
            if($real_class!==null) {
                $params_temp[]=self::make($real_class);
                continue;
            }
            elseif($param->isDefaultValueAvailable())
                $params_temp[]=$param->getDefaultValue();
            else if($param->allowsNull())
                $params_temp[]=null;
            else
                throw new Exception('Parameter "'.$param->getName().'" of "'.$param.'" is not valid.',0,array(
                    'class'=>$param,
                    'parameter'=>$param->getName(),
                    'error'=>'The parameter type is not valid or the parameter value is not set.'
                ));
        }
        return $params_temp;
    }

    /**
     * 判断参数是否符合预期类型
     * 
     * @access protected
     * @param mixed $arg 参数
     * @param array $types 预期类型
     * @return bool
     */
    static protected function isValidType(mixed $arg,array $types): bool {
        $arg_type=gettype($arg);
        // 直接匹配 PHP 内置类型
        if(in_array($arg_type,$types,true)) return true;
        // 类型为空字符串(无类型约束)
        if($types===['']||in_array('',$types,true)) return true;
        // mixed 表示任何类型都合法
        if(in_array('mixed',$types,true)) return true;
        // 如果参数是对象，检查是否符合给定类名
        if($arg_type==='object') {
            foreach($types as $t) {
                if(class_exists($t) && $arg instanceof $t) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * 寻找一个类的可实例化的子类(仅结果支持别名和绑定)
     * 
     * @access public
     * @param string $class 类名
     * @return ?string
     */
    static public function findSubClass(string $class): ?string {
        // 判断类是否存在
        if(!class_exists($class)&&!interface_exists($class))
            return null;
        // 获取所有子类
        $sub_classes=get_declared_classes();
        $sub_classes=array_filter($sub_classes,function($sub_class) use ($class) {
            return is_subclass_of($sub_class,$class);
        });
        // 判断是否存在可实例化的子类
        foreach($sub_classes as $sub_class) {
            $sub_class=self::getRealClass($sub_class);
            $ref=self::getReflection($sub_class);
            if($ref->isInstantiable())
                return $sub_class;
        }
        // 如果均不可实例化则递归查找,直到找到可实例化的子类
        foreach($sub_classes as $sub_class) {
            $sub_class=self::findSubClass($sub_class);
            if($sub_class!==null)
                return $sub_class;
        }
        return null;
    }

    /**
     * 逐级寻找一个类的直接子类并查找可实例化的子类(支持别名和绑定)
     * 
     * @access public
     * @param string $class 类名
     * @param array $flags 标识(请不要传入该参数,该参数主要用于防止解析死循环)
     * @return ?string
     */
    static public function findDirectSubClassRecursive(string $class,array &$flags=array()): ?string {
        // 获取真实类名
        $class=self::getRealClass($class);
        // 如果标识重复则直接返回
        if(in_array($class,$flags))
            return null;
        // 判断类是否存在
        if(!class_exists($class)&&!interface_exists($class))
            return null;
        // 判断自身是否可实例化
        $ref=self::getReflection($class);
        if($ref->isInstantiable())
            return $class;
        // 获取所有已声明类
        $all_classes=get_declared_classes();
        // 区分是类还是接口
        $is_class=class_exists($class);
        $is_interface=interface_exists($class);
        // 筛选直接子类或直接实现接口的类
        $direct_sub_classes=array_filter($all_classes,function($sub_class) use ($class,$is_class,$is_interface) {
            // 直接继承
            if($is_class&&get_parent_class($sub_class)===$class)
                return true;
            // 直接实现接口
            if($is_interface) {
                $all_interfaces=class_implements($sub_class,true);
                $parent=get_parent_class($sub_class);
                $parent_interfaces=$parent?class_implements($parent,true):[];
                $direct_interfaces=array_diff($all_interfaces,$parent_interfaces);
                if(in_array($class,$direct_interfaces,true))
                    return true;
            }
            return false;
        });
        // 遍历直接子类，找到可实例化的
        foreach($direct_sub_classes as $sub_class) {
            $sub_ref=self::getReflection($sub_class);
            if($sub_ref->isInstantiable())
                return $sub_class;
            // 递归查找子类的子类
            $flags[]=$class;
            $found=self::findDirectSubClassRecursive($sub_class,$flags);
            // 移出标识中的目标类
            array_pop($flags);
            if($found!==null)
                return $found;
        }
        // 没有找到可实例化子类
        return null;
    }

    /**
     * 将一个PHP类型转为gettype()返回的类型
     * 
     * @access public
     * @param string $type 类型
     * @return string
     */
    static public function getStandardType(string $type): string {
        // 清除类型前缀
        $type=str_replace('?','',$type);
        $list=array(
            'int'=>'integer',
            'bool'=>'boolean',
            'float'=>'double',
            'null'=>'NULL'
        );
        if(isset($list[$type]))
            return $list[$type];
        return $type;
    }

    /**
     * 将一组PHP类型转为gettype()返回的类型
     * 
     * @access public
     * @param array $types 类型数组
     * @return array
     */
    static public function getStandardTypes(array $types): array {
        $result=array();
        foreach($types as $type)
            $result[]=self::getStandardType($type);
        return $result;
    }

    /**
     * 获取给出类型中的第一个可实例化的类(支持别名和绑定)
     * 
     * @access public
     * @param array $types 类型数组
     * @return ?string
     */
    static public function getFirstInstantiableClass(array $types): ?string {
        foreach($types as $type) {
            // 判断是否可以实例化该类
            $class_name=self::getRealClass($type);
            if(class_exists($class_name)) {
                // 通过反射判断是否可以实例化该类
                $ref_type=self::getReflection($class_name);
                if(!$ref_type->isInstantiable()) {
                    // 如果不可以实例化则尝试寻找一个可实例化的子类
                    $class_name=self::findDirectSubClassRecursive($class_name);
                    if($class_name!==null)
                        return $class_name;
                    else
                        continue;
                }
                return $class_name;
            }
            // 处理接口
            if(interface_exists($class_name)) {
                // 尝试寻找可实例化的实现类
                $class_name=self::findDirectSubClassRecursive($class_name);
                if($class_name!==null)
                    return $class_name;
                continue;
            }
        }
        return null;
    }

}