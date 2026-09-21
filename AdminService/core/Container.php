<?php

namespace AdminService;

use ReflectionClass;

use function class_exists;
use function explode;
use function in_array;
use function interface_exists;
use function is_string;
use ReflectionException;
use AdminService\Exception;

final class Container implements \base\Container {

    /**
     * 父容器(请求级容器 fork 自应用级容器)
     *
     * - 解析时先查自身, 未命中再委托父容器(实例 / 绑定 / 别名 / 单例)
     * - `instance()` **只写自身**, 因此请求级容器不会污染应用级
     *
     * @var Container|null
     */
    private ?Container $parent=null;

    /**
     * 对象实例容器
     * @var array
     */
    private array $container=array();

    /**
     * 未被实例化的类容器(绑定表: 抽象名 → 实现类)
     */
    private array $class_container=array();

    /**
     * 别名表(与绑定分表: 名字 → 名字)
     * @var array<string,string>
     */
    private array $alias_container=array();

    /**
     * 单例表(抽象名 → 真实类名)
     * @var array<string,string>
     */
    private array $singleton_container=array();

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
     * 自动装配器(三路装配: 属性 / Setter / 生命周期方法)
     * @var Autowire
     */
    private Autowire $autowire;

    /**
     * 构造方法
     *
     * - 四个内核组件(反射缓存 / 参数解析器 / 类查找器 / 装配器)都是实例对象; 依赖方向为 容器 → 组件(单向)
     * - 组件需要的"类名解析""按类型取实例"能力由容器以回调回填, 避免组件反向依赖容器
     *
     * @access public
     * @param ReflectionCache|null $reflections 反射缓存(请求级容器与父容器共享同一份缓存)
     */
    public function __construct(?ReflectionCache $reflections=null) {
        $this->reflections=$reflections??new ReflectionCache();
        $this->arguments=new ArgumentResolver($this->reflections);
        $this->classes=new ClassFinder($this->reflections);
        $this->autowire=new Autowire($this->reflections);
        // 装配器: 类名解析 + 按类名装配实例 + 参数解析(生命周期方法注入) + 代理创建(注入容器)
        $this->autowire->setArgumentResolver($this->arguments);
        // 参数解析器: 配置项取值(接到框架配置; 组件因此不直接依赖配置实现)
        $this->arguments->setValueResolver(function(string $key,mixed $default=null): mixed {
            return Config::get($key,$default);
        });
        $this->autowire->setProxyResolver(function(string $class,array $args=array()): DynamicProxy {
            $proxy=new DynamicProxy($class,...$args);
            $proxy->setContainer($this);
            return $proxy;
        });
        $this->autowire->setClassResolver(function(string $class): string {
            return $this->resolve($class);
        });
        $this->autowire->setInstanceResolver(function(string $class,bool $is_force=false,array &$flags=array()): object {
            return $this->makeInternal($class,$is_force,$flags);
        });
        // 类查找器: 别名与绑定解析
        $this->classes->setClassResolver(function(string $class): string {
            return $this->resolve($class);
        });
        // 参数解析器: 按类型解析出实例(先看容器里已有的实例, 再找可实例化的类交给容器装配)
        $this->arguments->setInstanceResolver(function(array $types): ?object {
            $bound=$this->getBoundInstance($types);
            if($bound!==null)
                return $bound;
            $real_class=$this->classes->getFirstInstantiableClass($types);
            if($real_class===null)
                return null;
            return $this->make($real_class);
        });
        // 容器自身登记: 按契约 `base\Container` 或按实现类名都能取到"当前容器"
        // (控制器/路由基类按契约依赖它)
        $this->container[\base\Container::class]=$this;
        $this->container[static::class]=$this;
    }

    /**
     * 按类型列表取"容器里已登记的实例"
     *
     * - 类型名与"解析别名/绑定后的真实类名"都查一遍
     * - 与 `get()` 的规则一致: 已登记的实例优先, 找不到才走类查找与装配
     *
     * @access private
     * @param array<string> $types 类型列表
     * @return object|null
     */
    private function getBoundInstance(array $types): ?object {
        foreach($types as $type) {
            if($type===''||$type==='NULL'||$type==='mixed')
                continue;
            $bound=$this->findInstance($type)??$this->findInstance($this->resolve($type));
            if($bound!==null)
                return $bound;
        }
        return null;
    }

    /**
     * 按名称取"已登记的实例"(先自身, 再父容器)
     *
     * - 请求级容器因此能看到应用级登记的对象(如配置、日志、数据库);`instance()` 只写自身
     * - 不把父容器的实例复制到自身: 父容器换实例后请求级容器立刻看到新的
     *
     * @access private
     * @param string $name 名称(类名 / 接口名 / 别名, 需已是真实类名或原名)
     * @return object|null
     */
    private function findInstance(string $name): ?object {
        if(isset($this->container[$name]))
            return $this->container[$name];
        if($this->parent!==null&&isset($this->parent->container[$name]))
            return $this->parent->container[$name];
        return null;
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
     * 获取对象(如果不存在则自动实例化)
     *
     * - 已登记的实例优先; 否则要求该类可实例化, 并登记解析结果(同一实现只构建一次)
     *
     * @access public
     * @template T of object
     * @param class-string<T>|string $name 对象名(类名 / 接口名 / 别名)
     * @return T|object 返回指定类的实例
     * @throws Exception
     */
    public function get(string $name): object {
        $name=$this->resolve($name);
        if(!isset($this->container[$name])) {
            // 自身没有则委托父容器(请求级容器能看到应用级登记的对象; 不缓存到自身, 父容器换实例后立刻生效)
            if($this->parent!==null&&isset($this->parent->container[$name]))
                return $this->parent->container[$name];
            // 如果不存在则判断是否存在该类
            if(!class_exists($name))
                throw new Exception('Class "'.$name.'" not found.');
            // 如果存在则判断是否可以实例化
            $ref=$this->reflections->getClass($name);
            if(!$ref->isInstantiable())
                throw new Exception('Class "'.$name.'" is not instantiable.');
            // 如果可以实例化则实例化一个新的对象
            $instance=$ref->newInstance();
            $this->container[$name]=$instance;
            // 单例绑定: 抽象名与实现类名指向同一实例
            $this->registerSingletons($name,$instance);
        }
        return $this->container[$name];
    }

    /**
     * 派生一个请求级子容器
     *
     * - **共享**: 绑定表 / 别名表 / 单例表 / 反射缓存(复制标量表, 复用缓存对象)
     * - **不共享**: 实例表(请求级容器读写自己的, 不污染应用级)
     * - 内核组件按子容器重新装配(它们的回调必须指向子容器, 否则实例会注册到父容器)
     *
     * @access public
     * @return static
     */
    public function fork(): static {
        $child=new static($this->reflections);
        $child->parent=$this;
        $child->class_container=$this->class_container;
        $child->alias_container=$this->alias_container;
        $child->singleton_container=$this->singleton_container;
        // 参数转换开关跟随父容器(它属进程级配置, 不是请求级状态)
        $child->arguments->setParamCast($this->arguments->getParamCast());
        return $child;
    }

    /**
     * 清空实例表(请求结束 / 复用时调用)
     *
     * - 绑定 / 别名 / 单例表与反射缓存**保留**(它们属应用级)
     * - 容器自身的登记会重新写回, 因此 `reset()` 后仍可按契约取到自身
     *
     * @access public
     * @return void
     */
    public function reset(): void {
        $this->container=array();
        $this->container[\base\Container::class]=$this;
        $this->container[static::class]=$this;
    }

    /**
     * 判断容器能否给出该名称
     *
     * - 别名 / 绑定 / 实例任一命中即为真; 否则看它是不是一个可构建的类或接口
     *
     * @access public
     * @param string $name 名称(类名 / 接口名 / 别名)
     * @return bool
     */
    public function has(string $name): bool {
        if(isset($this->container[$name])||isset($this->alias_container[$name])||isset($this->class_container[$name]))
            return true;
        if($this->parent!==null&&$this->parent->has($name))
            return true;
        $real=$this->resolve($name);
        // 只认"可实例化的具体类": 未绑定的接口/抽象类不算"容器能给出"(否则按接口取会抛异常)
        return isset($this->container[$real])||(class_exists($real)&&$this->reflections->getClass($real)->isInstantiable());
    }

    /**
     * 判断"实例是否已登记"(自身或父容器)
     *
     * @access public
     * @param string $name 名称(类名 / 接口名 / 别名)
     * @return bool
     */
    public function hasInstance(string $name): bool {
        if($this->findInstance($name)!==null)
            return true;
        $real=$this->resolve($name);
        return $real!==$name&&$this->findInstance($real)!==null;
    }

    /**
     * 登记一个已实例化对象(按真实类名存储)
     *
     * @access public
     * @param string $name 对象名(类名 / 接口名 / 别名)
     * @param object $object 对象
     * @return void
     */
    public function instance(string $name,object $object): void {
        $this->container[$this->resolve($name)]=$object;
    }

    /**
     * 解析名称的真实类名(先别名后绑定, 支持嵌套, 带深度保护)
     *
     * - 内部使用: 对外请用 `has()` 判断存在性、用 `get()` 取用
     *
     * @access private
     * @param string $name 别名 / 抽象类 / 接口 / 类名
     * @param int $max_depth 最大解析深度
     * @return string
     * @throws Exception
     */
    private function resolve(string $name,int $max_depth=255): string {
        while(true) {
            if(isset($this->alias_container[$name]))
                $target=$this->alias_container[$name];
            elseif(isset($this->class_container[$name]))
                $target=$this->class_container[$name];
            else
                return $name;
            // 自身映射(自别名 / 自绑定)原样返回
            if($target===$name)
                return $name;
            if($max_depth--<=0)
                throw new Exception('Maximum recursion depth exceeded while resolving class "'.$name.'"');
            $name=$target;
        }
    }

    /**
     * 为抽象类或接口绑定实现类(会覆盖已存在的绑定)
     *
     * - 支持子类逐级查找(除非方法特殊说明)
     * - 支持嵌套绑定; 解析结果按**真实类名**复用(同一实现只构建一次)
     *
     * @access public
     * @param string $abstract 抽象类或接口名
     * @param string $concrete 实现类
     * @return void
     * @throws Exception
     */
    public function bind(string $abstract,string $concrete): void {
        // 如果类不存在则抛出异常
        if(!class_exists($concrete))
            throw new Exception('Class "'.$concrete.'" not found.');
        // 判断新绑定是否会形成循环关系
        if($this->wouldCycle($abstract,$concrete))
            throw new Exception('Circular dependency detected for "'.$abstract.'" and "'.$concrete.'"');
        $this->class_container[$abstract]=$concrete;
    }

    /**
     * 为名称设置别名(与绑定**分表**)
     *
     * - 别名只描述"名字 → 名字", 不承诺目标是可实例化的类
     * - 支持嵌套与链式解析(解析时先别名后绑定)
     *
     * @access public
     * @param string $alias 别名
     * @param string $abstract 目标名称(类名 / 接口名 / 另一个别名)
     * @return void
     * @throws Exception
     */
    public function alias(string $alias,string $abstract): void {
        if($this->wouldCycle($alias,$abstract))
            throw new Exception('Circular alias detected for "'.$alias.'" and "'.$abstract.'"');
        $this->alias_container[$alias]=$abstract;
    }

    /**
     * 单例绑定:解析后**抽象名与实现类名指向同一实例**
     *
     * - `bind()` 只保证"按真实类名复用";`singleton()` 额外把抽象名也指向该实例
     * - **懒解析**:首次解析(经抽象名或实现类名)时登记, 不在绑定时构建
     *
     * @access public
     * @param string $abstract 抽象类或接口名
     * @param string $concrete 实现类
     * @return void
     * @throws Exception
     */
    public function singleton(string $abstract,string $concrete): void {
        $this->bind($abstract,$concrete);
        $this->singleton_container[$abstract]=$this->resolve($abstract);
    }

    /**
     * 批量绑定(抽象 → 实现)
     *
     * @access public
     * @param array<string,string> $bindings 绑定表(键为抽象名, 值为实现类)
     * @return void
     * @throws Exception
     */
    public function bindAll(array $bindings): void {
        foreach($bindings as $abstract=>$concrete)
            $this->bind($abstract,$concrete);
    }

    /**
     * 判断目标是否会造成解析循环(查别名表与绑定表两条链)
     *
     * @access private
     * @param string $abstract 起点名称
     * @param string $concrete 目标名称
     * @return bool
     */
    private function wouldCycle(string $abstract,string $concrete): bool {
        $visited=array($abstract);
        $name=$concrete;
        while(true) {
            if($name===$abstract||in_array($name,$visited,true))
                return true;
            $visited[]=$name;
            if(isset($this->alias_container[$name]))
                $name=$this->alias_container[$name];
            elseif(isset($this->class_container[$name]))
                $name=$this->class_container[$name];
            else
                return false;
        }
    }

    /**
     * 单例登记:把配置为单例的抽象名也指向刚解析出的实例
     *
     * @access private
     * @param string $real_class 真实类名
     * @param object $instance 实例
     * @return void
     */
    private function registerSingletons(string $real_class,object $instance): void {
        foreach($this->singleton_container as $abstract=>$concrete) {
            if($concrete===$real_class)
                $this->container[$abstract]=$instance;
        }
    }


    /**
     * 通过自动依赖注入实例化一个对象
     *
     * 注意: 依赖简单支持抽象类和接口,重复依赖可能会抛出找不到对象的异常,
     * 这种情况请先使用App::instance(Class::class,new Class())添加到容器中
     *
     * @access public
     * @template T of object
     * @param class-string<T> $name 对象名
     * @param bool $is_force 是否强制实例化(仅对当前对象有效,不会影响依赖)
     * @param array<mixed> $flags 标识(请不要传入该参数,该参数主要用于防止依赖注入死循环)
     * @return T|object
     * @throws Exception|ReflectionException
     */
    public function make(string $name): object {
        $flags=array();
        return $this->makeInternal($name,false,$flags);
    }

    /**
     * 强制新建对象
     *
     * - 跳过"复用已登记实例", 但仍做完整装配(属性 / Setter / 生命周期方法)
     * - 结果会覆盖登记(与 `make()` 一致), 因此适合"每请求要一个干净实例"的场景
     *
     * @access public
     * @param string $name 对象名(类名 / 接口名 / 别名)
     * @return object
     * @throws Exception
     */
    public function fresh(string $name): object {
        $flags=array();
        return $this->makeInternal($name,true,$flags);
    }

    /**
     * 构建对象(内部实现)
     *
     * - `$is_force` 为假时复用已登记实例;`$flags` 为**引用传递**的构建栈, 用于阻断依赖注入死循环
     *
     * @access private
     * @param string $name 对象名(类名 / 接口名 / 别名)
     * @param bool $is_force 是否强制新建
     * @param array<mixed> $flags 构建标识(引用传递)
     * @return object
     * @throws Exception
     */
    private function makeInternal(string $name,bool $is_force,array &$flags): object {
        $name=$this->resolve($name);
        // 复用规则: 自身或**父容器**已登记则直接返回(除非强制新建); 构建栈里出现同一类也直接返回(阻断循环)
        $bound=$this->findInstance($name);
        if((!$is_force&&$bound!==null)||in_array($name,$flags))
            return $bound??$this->get($name);
        // 判断是否为接口
        if(interface_exists($name)) {
            // 寻找一个可实例化的子类
            $real_class=$this->classes->getFirstInstantiableClass(array($name));
            if($real_class===null)
                throw new Exception('Class "'.$name.'" is not instantiable.');
            return $this->makeInternal($real_class,false,$flags);
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
            return $this->makeInternal($real_class,false,$flags);
        }
        $constructor=$ref->getConstructor();
        if($constructor!==null) {
            // 构造函数形参统一交给参数解析器(已登记实例优先 / 按类型装配 / #[Config] 配置项注入)
            $args=$this->arguments->merge($constructor->getParameters(),array(),true);
            // 传入构造函数参数实例化一个新的对象
            $object=$ref->newInstanceArgs($args);
        } else
            // 如果没有构造函数则直接实例化一个新的对象
            $object=$ref->newInstance();
        // 自动注入属性
        $this->autowire->autowire($object,$flags);
        // 将对象添加到容器中(并处理单例绑定的抽象名)
        $this->container[$name]=$object;
        $this->registerSingletons($name,$object);
        // 移出标识中的当前对象
        array_pop($flags);
        return $object;
    }

    /**
     * 构建一个新对象
     *
     * - 支持传入构造函数参数($args 中的关系型键按参数名赋值, 索引键按位置赋值)
     * - 与 `new` 的区别: **会做完整装配**(属性 / Setter / 生命周期方法注入), 解决"带参构造静默少注入"的问题
     * - 结果**不登记**到实例容器(要复用的场景请用 `get()` / `make()`)
     *
     * @access public
     * @template T of object
     * @param class-string<T>|string $__name 对象名(类名 / 接口名 / 别名)
     * @param mixed ...$args 构造函数参数($args中不允许传入“__name”参数)
     * @return T|object
     * @throws Exception|ReflectionException
     */
    public function build(string $__name,...$args): object {
        // 获取真实类名
        $__name=$this->resolve($__name);
        // 判断类或接口是否存在
        if(!class_exists($__name)&&!interface_exists($__name))
            throw new Exception('Class "'.$__name.'" not found.');
        $ref=$this->reflections->getClass($__name);
        // 判断是否可以被实例化,如果不能则尝试寻找一个可实例化的子类
        if(!$ref->isInstantiable()) {
            $real_class=$this->classes->getFirstInstantiableClass(array($__name));
            if($real_class===null)
                throw new Exception('Class "'.$__name.'" is not instantiable.');
            return $this->build($real_class,...$args);
        }
        // 获取构造函数的参数
        $constructor=$ref->getConstructor();
        if($constructor===null)
            // 如果构造函数不存在则直接实例化一个对象
            $object=$ref->newInstance();
        else {
            $params=$constructor->getParameters();
            $args_temp=$this->arguments->merge($params,$args,true);
            $object=$ref->newInstanceArgs($args_temp);
        }
        // 完整装配(以自身名字作为构建标识起点, 阻断自引用注入的无限递归)
        $flags=array($__name);
        $this->autowire->autowire($object,$flags);
        // 直接返回对象,不添加到实例容器中
        return $object;
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
