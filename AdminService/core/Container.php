<?php

namespace AdminService;

use ReflectionClass;
use ReflectionParameter;

use function array_key_last;
use function array_pop;
use function class_exists;
use function implode;
use function in_array;
use function interface_exists;
use function is_array;
use function is_string;
use ReflectionException;
use AdminService\Exception;
use AdminService\exception\CircularDependencyException;

/**
 * 容器实现
 *
 * - 实例表按容器隔离; 请求级容器由 `fork()` 派生, 实例查找会回落父容器
 * - 绑定 / 别名 / 单例表在 `fork()` 时复制快照, 之后父容器的变更不回溯
 * - 反射缓存由派生容器共享; 内核组件以回调方式取得解析能力, 不反向依赖容器
 *
 * @access public
 * @package AdminService
 * @version 2.0.0
 */
final class Container implements \base\Container {

    /**
     * 父容器(请求级容器 fork 自应用级容器)
     *
     * - 实例查找: 先查自身, 未命中再查父容器
     * - 绑定 / 别名 / 单例表: fork 时复制快照, 不随父容器后续变更
     * - `instance()` **只写自身**, 因此请求级容器不会污染应用级
     *
     * @var Container|null
     */
    private ?Container $parent=null;

    /**
     * 当前构建栈(`makeInternal()` / `build()` 的构建标识)
     *
     * - 参数解析器回调与嵌套公共入口据此解析依赖
     * - 进入构建时同步, 退出时恢复为上一层; 顶层调用为空数组, 嵌套公共入口沿用当前栈
     *
     * @var array<mixed>
     */
    private array $build_flags=array();

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
        // 参数解析器: 配置项取值(接到"容器里登记的配置实例"; 组件因此既不依赖配置实现, 也不依赖配置门面)
        // - 只认**已登记**的实例(不构建类), 且按契约名与"别名解析后的实现类名"都查一遍(`getBoundInstance()`):
        //   实例是按实现类名登记的, 直接查契约名会漏(那会让 `#[Config]` 静默退回默认值)
        // - 别名只是"契约名 → 实现类名"的快捷方式, 不是唯一入口: 别名缺失时再找可实例化实现类,
        //   并取它**已登记**的实例。这样 `app.alias` 漏配不会让 `#[Config]` 悄悄变成默认值。
        // - 仍查不到时回落默认值(而不是抛): 引导期就会走到这里, 抛异常会把真正的错误盖掉;
        //   裸容器(没有任何配置实例)保持"给默认值"的既有语义。
        $this->arguments->setValueResolver(function(string $key,mixed $default=null): mixed {
            $config=$this->getBoundInstance(array(\base\ConfigInterface::class));
            if($config===null)
                $config=$this->findBoundImplementation(\base\ConfigInterface::class);
            if($config===null) {
                $real_class=$this->classes->getFirstInstantiableClass(array(\base\ConfigInterface::class));
                if($real_class!==null)
                    $config=$this->findInstance($real_class);
            }
            return $config instanceof \base\ConfigInterface?$config->get($key,$default):$default;
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
        // 构造参数与生命周期方法参数沿用当前构建栈(`$this->build_flags`)
        $this->arguments->setInstanceResolver(function(array $types,?ReflectionParameter $param=null): ?object {
            $bound=$this->getBoundInstance($types);
            if($bound!==null)
                return $bound;
            $real_class=$this->classes->getFirstInstantiableClass($types);
            if($real_class===null)
                return null;
            // 可选的自引用参数交给参数解析器取默认值 / null, 不生成裸实例
            if(in_array($real_class,$this->build_flags,true)
                &&$param!==null
                &&($param->isDefaultValueAvailable()||$param->allowsNull()))
                return null;
            return $this->makeInternal($real_class,false,$this->build_flags);
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
            $bound=$this->findInstance($type);
            if($bound!==null)
                return $bound;
            $real=$this->resolve($type);
            $bound=$this->findInstance($real);
            if($bound!==null)
                return $bound;
            if(interface_exists($real)||(class_exists($real)&&$this->reflections->getClass($real)->isAbstract())) {
                $implementation=$this->findBoundImplementation($real);
                if($implementation!==null)
                    return $implementation;
            }
        }
        return null;
    }

    /**
     * 刷新抽象类或接口名上的实例登记
     *
     * - 仅 `fresh()` 且原抽象名已有登记时使用
     * - 写入当前容器, 子容器会覆盖父容器的同名实例
     *
     * @access private
     * @param string $name 抽象类或接口名
     * @param object $object 新实例
     * @param object|null $bound 原登记实例
     * @param bool $is_force 是否强制新建
     * @return void
     */
    private function refreshAbstractRegistration(string $name,object $object,?object $bound,bool $is_force): void {
        if(!$is_force||$bound===null)
            return;
        $this->container[$name]=$object;
        $this->refreshSelfRegistration($name,$object,$is_force);
    }

    /**
     * 同步容器自身的两个登记键
     *
     * - 仅 `fresh()` 重建当前容器对象时使用
     * - 不依赖登记实例是否仍是当前容器: 连续 `fresh()` 时两个键都要指向最新容器
     *
     * @access private
     * @param string $name 对象名
     * @param object $object 新实例
     * @param bool $is_force 是否强制新建
     * @return void
     */
    private function refreshSelfRegistration(string $name,object $object,bool $is_force): void {
        if(!$is_force)
            return;
        if($name!==static::class&&$name!==\base\Container::class)
            return;
        $this->container[\base\Container::class]=$object;
        $this->container[static::class]=$object;
    }

    /**
     * 查找容器里已登记的、实现指定接口的实例
     *
     * - 用于"契约没有别名、但实现类实例已登记"的多实现场景
     * - 先查自身, 再查父容器; 实例登记顺序决定多份实现时的优先级
     *
     * @access private
     * @param string $interface 接口名
     * @return object|null
     */
    private function findBoundImplementation(string $interface): ?object {
        foreach($this->container as $object) {
            if($object instanceof $interface)
                return $object;
        }
        if($this->parent!==null)
            return $this->parent->findBoundImplementation($interface);
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
     * 按名字取反射类对象(取不到时抛既有的"类不存在"文案)
     *
     * - **这是容器边界上"类是否存在"的唯一判定口**: 不再逐个方法写 `class_exists` / `interface_exists` 预检查
     *   —— 那几个预检查只是"友好报错", 而"取反射"这一步本来就会触发自动加载(该加载的躲不掉),
     *   预检查等于把同一件事做两遍, 还多出几处需要跟 PHP 语义保持同步的地方
     * - 语义与旧写法一致: 不存在的名字 → `Class "X" not found.`;接口/抽象类取得到反射, 由调用方判 `isInstantiable()`
     *
     * @access private
     * @param string $name 类名(已解析别名/绑定后的真实名字)
     * @return ReflectionClass
     * @throws Exception 类不存在
     */
    private function getReflection(string $name): ReflectionClass {
        try {
            return $this->reflections->getClass($name);
        } catch(ReflectionException) {
            throw new Exception('Class "'.$name.'" not found.');
        }
    }

    /**
     * 获取对象(如果不存在则自动实例化)
     *
     * - 已登记的实例优先; 否则做**完整装配**(构造参数 / 属性 / Setter / 生命周期方法), 并登记解析结果
     * - 与 `make()` 的区别只是语义命名: 两者都复用已登记实例; 要强制新建请用 `fresh()`
     * - `AutowireMode::Deferred` 的任务只在最外层成功后刷新; 嵌套构建不会提前执行
     * - 根构建失败(包括延迟刷新失败)会回滚本轮新增的实例登记, 已有实例保持不变
     *
     * @access public
     * @template T of object
     * @param class-string<T>|string $name 对象名(类名 / 接口名 / 别名)
     * @return T 返回指定类的实例
     * @throws Exception
     */
    public function get(string $name): object {
        $is_root=$this->build_flags===array();
        $snapshot=$is_root?$this->container:null;
        $flags=$this->build_flags;
        try {
            $object=$this->makeInternal($name,false,$flags);
            if($is_root)
                $this->autowire->flushDeferred();
            return $object;
        } catch(\Throwable $e) {
            $this->rollbackRootContainer($snapshot);
            throw $e;
        }
    }

    /**
     * 直接实例化(不装配、不递归解析依赖)
     *
     * - 只用于**循环依赖**的回落: 构建栈里已经出现同一类时, 再递归会无限循环,
     *   这里给出一个"裸实例"让对象图能收口
     * - 裸实例只执行无参构造, 不做属性 / Setter / 生命周期方法注入; 需要必填构造参数的类
     *   会抛受控异常
     * - 正常构建路径请走 `makeInternal()` / `get()`
     *
     * @access private
     * @param string $name 已解析的真实类名
     * @return object
     * @throws Exception
     */
    private function createBare(string $name): object {
        $name=$this->resolve($name);
        if(isset($this->container[$name]))
            return $this->container[$name];
        if($this->parent!==null&&isset($this->parent->container[$name]))
            return $this->parent->container[$name];
        $ref=$this->getReflection($name);
        if(!$ref->isInstantiable())
            throw new Exception('Class "'.$name.'" is not instantiable.');
        $constructor=$ref->getConstructor();
        if($constructor!==null&&$constructor->getNumberOfRequiredParameters()>0) {
            $stack=$this->build_flags;
            $stack[]=$name;
            throw new CircularDependencyException(
                'Circular dependency detected while resolving "'.$name.'" ('.implode(' -> ',$stack).
                '): the class has required constructor parameters and cannot be instantiated without them.',
                0,
                array('class'=>$name,'build_stack'=>$stack)
            );
        }
        $instance=$ref->newInstance();
        $this->container[$name]=$instance;
        $this->registerSingletons($name,$instance);
        return $instance;
    }

    /**
     * 派生一个请求级子容器
     *
     * - **复制快照**: 绑定表 / 别名表 / 单例表; 之后父容器的 `bind()` / `alias()` /
     *   `singleton()` 不会回溯到已有子容器
     * - **共享**: 反射缓存(同一对象, 不随子容器销毁)
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
     * 清空实例表(复用容器时调用)
     *
     * - 绑定 / 别名 / 单例表与反射缓存**保留**(它们属应用级)
     * - 请求级容器由 `Application::handle()` 在下一次请求开始时调用本方法
     * - 容器自身的登记会重新写回, 因此 `reset()` 后仍可按契约取到自身
     * - 尚未执行的自动注入延迟任务会一并丢弃, 避免引用已清空的实例
     *
     * @access public
     * @return void
     */
    public function reset(): void {
        $this->container=array();
        $this->container[\base\Container::class]=$this;
        $this->container[static::class]=$this;
        // 丢弃未执行的延迟任务, 避免它们引用已被清空的实例
        $this->autowire->clearDeferred();
    }

    /**
     * 回滚根构建失败时新增的实例登记
     *
     * - `get()` / `make()` / `fresh()` / `build()` 在最外层构建前保存实例表快照
     * - 构建或延迟刷新失败时恢复快照, 避免半装配对象留在容器里被后续调用复用
     * - 只回滚实例表; 绑定 / 别名表, 以及生命周期方法产生的外部副作用不在此范围
     *
     * @access private
     * @param array|null $snapshot 根构建前的实例表快照; 嵌套构建传 null
     * @return void
     */
    private function rollbackRootContainer(?array $snapshot): void {
        if($snapshot===null)
            return;
        $this->container=$snapshot;
        $this->autowire->clearDeferred();
    }

    /**
     * 判断容器能否定位到该名称的可实例化类
     *
     * - 检查范围: 先解析本地绑定 / 别名, 再查自身或父容器的实例、已声明的实现类
     * - 不检查构造参数是否可解析
     * - 绑定 / 别名表是 `fork()` 时的快照, 父容器之后新增的名字不会被子容器看到
     *
     * @access public
     * @param string $name 名称(类名 / 接口名 / 别名)
     * @return bool
     */
    public function has(string $name): bool {
        try {
            // 先解析别名 / 绑定, 再按解析结果查实例, 与 make() 的解析顺序一致
            $real=$this->resolve($name);
            if($this->findInstance($real)!==null)
                return true;
            if(interface_exists($real)) {
                if($this->classes->getFirstInstantiableClass(array($real))!==null)
                    return true;
            } elseif(class_exists($real)) {
                $ref=$this->reflections->getClass($real);
                if($ref->isInstantiable()||$this->classes->getFirstInstantiableClass(array($real))!==null)
                    return true;
            }
            return false;
        } catch(\Throwable) {
            return false;
        }
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
     * - 只看本容器的绑定 / 别名表; 请求级容器的表是 `fork()` 时的快照
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
     * - 只写当前容器的绑定表; 子类 / 实现类查找由 `ClassFinder` 在解析实例时完成
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
     * 获取对象(**复用**已登记实例; 未登记则实例化并做完整装配)
     *
     * - 解析结果按真实类名登记, 因此同一实现**在每个容器实例内**只构建一次;
     *   要每次新建请用 `fresh()`
     * - `AutowireMode::Deferred` 的任务在根对象登记后刷新; 嵌套构建不会提前执行
     * - 根构建失败(包括延迟刷新失败)会回滚本轮新增的实例登记, 已有实例保持不变
     * - 依赖简单支持抽象类和接口,重复依赖可能会抛出找不到对象的异常,
     *   这种情况请先使用 App::instance(Class::class,new Class()) 添加到容器中
     * - 循环依赖被截断时, 回落的实例不执行属性 / Setter / 生命周期方法注入;
     *   可选构造参数回落默认值 / null, 无法回落时抛 `CircularDependencyException`
     *
     * @access public
     * @template T of object
     * @param class-string<T>|string $name 对象名(类名 / 接口名 / 别名)
     * @return T
     * @throws Exception|ReflectionException
     */
    public function make(string $name): object {
        $is_root=$this->build_flags===array();
        $snapshot=$is_root?$this->container:null;
        $flags=$this->build_flags;
        try {
            $object=$this->makeInternal($name,false,$flags);
            if($is_root)
                $this->autowire->flushDeferred();
            return $object;
        } catch(\Throwable $e) {
            $this->rollbackRootContainer($snapshot);
            throw $e;
        }
    }

    /**
     * 强制新建对象
     *
     * - 跳过"复用已登记实例", 但仍做完整装配(属性 / Setter / 生命周期方法)
     * - 传接口/抽象类名时同样强制新建查找到的实现类, 不复用该实现类的已登记实例
     * - 结果覆盖解析结果的登记(与 `make()` 一致); 接口 / 抽象类名已有实例登记时同步指向新实例
     * - 子容器刷新接口 / 抽象类登记时写入自身, 不修改父容器
     * - 重建容器自身时两个自登记键同步指向新容器
     * - 因此适合"每请求要一个干净实例"的场景
     * - `AutowireMode::Deferred` 的任务在根对象登记后刷新
     * - 根构建失败(包括延迟刷新失败)会回滚本轮新增的实例登记; 若原先已有登记, 失败后保留原实例
     * - 循环依赖回落对象的限制与 `make()` 相同
     *
     * @access public
     * @template T of object
     * @param class-string<T>|string $name 对象名(类名 / 接口名 / 别名)
     * @return T
     * @throws Exception
     */
    public function fresh(string $name): object {
        $is_root=$this->build_flags===array();
        $snapshot=$is_root?$this->container:null;
        $flags=$this->build_flags;
        try {
            $object=$this->makeInternal($name,true,$flags);
            if($is_root)
                $this->autowire->flushDeferred();
            return $object;
        } catch(\Throwable $e) {
            $this->rollbackRootContainer($snapshot);
            throw $e;
        }
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
        $previous_flags=$this->build_flags;
        $this->build_flags=&$flags;
        $pushed=false;
        try {
            $name=$this->resolve($name);
            // 复用规则: 自身或**父容器**已登记则直接返回(除非强制新建); 构建栈里出现同一类也直接返回(阻断循环)
            $bound=$this->findInstance($name);
            if((!$is_force&&$bound!==null)||in_array($name,$flags))
                return $bound??$this->createBare($name);
            // 判断是否为接口
            if(interface_exists($name)) {
                // 寻找一个可实例化的子类
                $real_class=$this->classes->getFirstInstantiableClass(array($name));
                if($real_class===null)
                    throw new Exception('Class "'.$name.'" is not instantiable.');
                $object=$this->makeInternal($real_class,$is_force,$flags);
                $this->refreshAbstractRegistration($name,$object,$bound,$is_force);
                return $object;
            }
            // 判断类或接口是否存在
            if(!class_exists($name))
                throw new Exception('Class "'.$name.'" not found.');
            // 将当前对象添加到标识中
            $flags[]=$name;
            $pushed=true;
            $ref=$this->reflections->getClass($name);
            // 判断自身是否可以被实例化
            if(!$ref->isInstantiable()) {
                // 寻找一个可实例化的子类
                $real_class=$this->classes->getFirstInstantiableClass(array($name));
                if($real_class===null)
                    throw new Exception('Class "'.$name.'" is not instantiable.');
                // 抽象名只是"查找入口", 不能留在构建栈里: 否则同一抽象类型的第二个属性会误判为循环
                array_pop($flags);
                $pushed=false;
                $object=$this->makeInternal($real_class,$is_force,$flags);
                $this->refreshAbstractRegistration($name,$object,$bound,$is_force);
                return $object;
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
            $this->refreshSelfRegistration($name,$object,$is_force);
            return $object;
        } finally {
            // 正常路径在 finally 弹出; 抽象类分支已提前弹出, 这里避免重复
            if($pushed) {
                $last=array_key_last($flags);
                if($last!==null&&$flags[$last]===$name)
                    array_pop($flags);
            }
            $this->build_flags=$previous_flags;
        }
    }

    /**
     * 构建一个新对象
     *
     * - 支持传入构造函数参数($args 中的关系型键按参数名赋值, 索引键按位置赋值)
     * - 与 `new` 的区别: **会做完整装配**(属性 / Setter / 生命周期方法注入), 解决"带参构造静默少注入"的问题
     * - 结果**本身不登记**到实例容器; 构造参数与属性依赖仍按 `make()` 解析并登记
     *   (要整体复用的场景请用 `get()` / `make()`)
     * - `AutowireMode::Deferred` 的任务在根构建完成后刷新; 被构建对象本身未登记,
     *   延迟任务若按"自身类型"解析会得到容器中的另一个实例。该实例可无参裸建时由
     *   `createBare()` 创建并登记; 目标有必需构造参数时, 延迟刷新会失败
     * - 根构建失败(包括延迟刷新失败)会回滚本轮新增的实例登记, 已有实例保持不变
     * - 构建过程中调用时沿用当前构建栈; 同一类重入抛 `CircularDependencyException`
     *
     * @access public
     * @template T of object
     * @param class-string<T>|string $__name 对象名(类名 / 接口名 / 别名)
     * @param mixed ...$args 构造函数参数($args中不允许传入“__name”参数)
     * @return T
     * @throws Exception|ReflectionException
     */
    public function build(string $__name,...$args): object {
        $is_root=$this->build_flags===array();
        $snapshot=$is_root?$this->container:null;
        // 获取真实类名
        $__name=$this->resolve($__name);
        if(in_array($__name,$this->build_flags,true)) {
            $stack=$this->build_flags;
            $stack[]=$__name;
            throw new CircularDependencyException(
                'Circular dependency detected while building "'.$__name.'" ('.implode(' -> ',$stack).').',
                0,
                array('class'=>$__name,'build_stack'=>$stack)
            );
        }
        // 判断类或接口是否存在(取不到反射即不存在)
        $ref=$this->getReflection($__name);
        // 判断是否可以被实例化,如果不能则尝试寻找一个可实例化的子类
        if(!$ref->isInstantiable()) {
            $real_class=$this->classes->getFirstInstantiableClass(array($__name));
            if($real_class===null)
                throw new Exception('Class "'.$__name.'" is not instantiable.');
            return $this->build($real_class,...$args);
        }
        // 本对象本身不登记, 但仍以它作为构建栈起点, 让构造参数 / 属性里的自引用能被截断
        $flags=$this->build_flags;
        $flags[]=$__name;
        $previous_flags=$this->build_flags;
        $this->build_flags=&$flags;
        try {
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
            // 完整装配
            $this->autowire->autowire($object,$flags);
            // 最外层构建成功后统一刷新延迟注入队列
            if($is_root)
                $this->autowire->flushDeferred();
            // 直接返回对象,不添加到实例容器中
            return $object;
        } catch(\Throwable $e) {
            $this->rollbackRootContainer($snapshot);
            throw $e;
        } finally {
            $this->build_flags=$previous_flags;
        }
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
