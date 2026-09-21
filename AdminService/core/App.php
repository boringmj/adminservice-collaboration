<?php

namespace AdminService;

use base\Container as ContainerContract;
use ReflectionClass;

use function count;

/**
 * 容器门面
 *
 * - **使用者的统一入口**: 所有方法都转发到"当前容器实例", 调用写法与旧版一致
 * - **无状态**: 唯一全局静态是指向当前容器的指针(`setInstance()` / `getInstance()`), 对标 Laravel 的 `Container::getInstance()`
 * - 框架内部不再通过门面取依赖(改构造注入 / 显式传递); 门面服务于使用者代码与既有调用点
 * - 转发面 = 实证在用的入口; 无调用点的方法不再出现在门面上(见纲领 4.5 / 4.6 的删除项)
 *
 * @access public
 * @package AdminService
 * @version 2.0.0
 */
final class App {

    /**
     * 当前容器(唯一全局静态)
     * @var ContainerContract|null
     */
    private static ?ContainerContract $instance=null;

    /**
     * 设置当前容器
     *
     * @access public
     * @param ContainerContract $container 容器实例
     * @return void
     */
    public static function setInstance(ContainerContract $container): void {
        self::$instance=$container;
    }

    /**
     * 获取当前容器
     *
     * - 未初始化时**抛出明确异常**(不再静默新建, 避免"门面看似可用、实际是个临时容器")
     * - 引导阶段请用 `App::init()` 或 `Main::init()`
     *
     * @access public
     * @return ContainerContract
     * @throws Exception
     */
    public static function getInstance(): ContainerContract {
        if(self::$instance===null)
            throw new Exception('容器尚未初始化, 请先调用 App::init() 或 Main::init()');
        return self::$instance;
    }

    /**
     * 是否已安装容器实例
     *
     * @access public
     * @return bool
     */
    public static function hasInstance(): bool {
        return self::$instance!==null;
    }

    /**
     * 初始化(引导)
     *
     * - 无实例则新建**应用级**容器; 有实例则沿用(幂等)
     * - 装配内容见 `Application::init()`: 配置绑定/别名 + 参数转换开关
     *
     * @access public
     * @param array<int|string,string> $classes 需要初始化的类
     * @return void
     * @throws Exception
     */
    public static function init(array $classes=array()): void {
        (new Application(self::$instance??new Container()))->init($classes);
    }

    /**
     * 获取对象(传入构造参数则不会添加到实例容器中)
     *
     * 注意: 依赖简单支持抽象类和接口,重复依赖可能会抛出找不到对象的异常,
     * 这种情况请先使用 App::instance(Class::class,new Class())添加到容器中
     *
     * @access public
     * @template T of object
     * @param class-string<T> $__name 对象名（类名）
     * @param mixed ...$args 构造函数参数($args中不允许传入“__name”参数)
     * @return T|object 返回指定类的实例
     * @throws Exception|\ReflectionException
     */
    public static function get(string $__name,...$args): object {
        if(count($args)>0)
            return self::build($__name,...$args);
        // 如果不存在则通过自动依赖注入实例化一个对象
        return self::getInstance()->make($__name);
    }

    /**
     * 判断容器能否给出该名称
     *
     * @access public
     * @param string $name 名称(类名 / 接口名 / 别名)
     * @return bool
     * @throws Exception
     */
    public static function has(string $name): bool {
        return self::getInstance()->has($name);
    }

    /**
     * 获取对象(容器中已有且未强制新建则复用)
     *
     * @access public
     * @param string $name 对象名(类名或别名)
     * @return object
     * @throws Exception|\ReflectionException
     */
    public static function make(string $name): object {
        return self::getInstance()->make($name);
    }

    /**
     * 强制新建对象(每次都是新实例, 做完整装配, 结果覆盖登记)
     *
     * @access public
     * @param string $name 对象名(类名或别名)
     * @return object
     * @throws Exception|\ReflectionException
     */
    public static function fresh(string $name): object {
        return self::getInstance()->fresh($name);
    }

    /**
     * 构建对象(带构造参数, 做完整装配, 不登记到实例容器)
     *
     * @access public
     * @param string $__name 类名或别名
     * @param mixed ...$args 构造函数参数
     * @return object
     * @throws Exception|\ReflectionException
     */
    public static function build(string $__name,...$args): object {
        return self::getInstance()->build($__name,...$args);
    }

    /**
     * 新建对象(同 `build`, 保留旧名)
     *
     * @access public
     * @param string $__name 类名或别名
     * @param mixed ...$args 构造函数参数
     * @return object
     * @throws Exception|\ReflectionException
     */
    public static function new(string $__name,...$args): object {
        return self::build($__name,...$args);
    }

    /**
     * 登记一个已实例化对象
     *
     * @access public
     * @param string $name 对象名
     * @param object $object 对象实例
     * @return void
     * @throws Exception
     */
    public static function instance(string $name,object $object): void {
        self::getInstance()->instance($name,$object);
    }

    /**
     * 为抽象类或接口绑定实现类
     *
     * @access public
     * @param string $abstract 抽象类或接口名(或别名)
     * @param string $concrete 实现类
     * @return void
     * @throws Exception
     */
    public static function bind(string $abstract,string $concrete): void {
        self::getInstance()->bind($abstract,$concrete);
    }

    /**
     * 为名称设置别名(与绑定分表)
     *
     * @access public
     * @param string $alias 别名
     * @param string $abstract 目标名称(类名 / 接口名 / 另一个别名)
     * @return void
     * @throws Exception
     */
    public static function alias(string $alias,string $abstract): void {
        self::getInstance()->alias($alias,$abstract);
    }

    /**
     * 单例绑定:解析后抽象名与实现类名指向同一实例
     *
     * @access public
     * @param string $abstract 抽象类或接口名
     * @param string $concrete 实现类
     * @return void
     * @throws Exception
     */
    public static function singleton(string $abstract,string $concrete): void {
        self::getInstance()->singleton($abstract,$concrete);
    }

    /**
     * 通过已有对象获取反射类对象(会缓存结果)
     *
     * @access public
     * @param object $object 对象
     * @return ReflectionClass
     */
    public static function getReflectionByObject(object $object): ReflectionClass {
        return self::getInstance()->getReflectionByObject($object);
    }

    /**
     * 设置是否允许标量参数静默转换
     *
     * @access public
     * @param bool $enable 是否允许
     * @return void
     * @throws Exception
     */
    public static function setParamCast(bool $enable): void {
        self::getInstance()->setParamCast($enable);
    }

    /**
     * 获取是否允许标量参数静默转换
     *
     * @access public
     * @return bool
     * @throws Exception
     */
    public static function getParamCast(): bool {
        return self::getInstance()->getParamCast();
    }

    /**
     * 获取全局数据
     *
     * @access public
     * @param string $name 数据名
     * @param mixed $default 默认值
     * @return mixed
     * @throws Exception
     */
    public static function getData(string $name,mixed $default=null): mixed {
        return self::getInstance()->getData($name,$default);
    }

    /**
     * 设置或添加全局数据
     *
     * @access public
     * @param string $name 数据名
     * @param mixed $data 数据
     * @return void
     * @throws Exception
     */
    public static function setData(string $name,mixed $data): void {
        self::getInstance()->setData($name,$data);
    }

    /**
     * 执行类或对象的方法
     *
     * @access public
     * @param object|string $object 对象或者类名
     * @param string $method 方法名
     * @param array<mixed> $args 方法参数(如果为关系型数组,则会将key作为参数名,value作为参数值,如果索引数组,则会逐一赋值,没有赋值的参数会使用默认值)
     * @return mixed
     * @throws Exception|\ReflectionException
     */
    public static function exec_class_function(object|string $object,string $method,array $args=array()): mixed {
        return self::getInstance()->exec_class_function($object,$method,$args);
    }

    /**
     * 执行函数
     *
     * @access public
     * @param string|array|callable $function 函数名(支持数组形式的类方法调用和闭包)
     * @param array<mixed> $args 函数参数(如果为关系型数组,则会将key作为参数名,value作为参数值,如果索引数组,则会逐一赋值,没有赋值的参数会使用默认值)
     * @return mixed
     * @throws Exception|\ReflectionException
     */
    public static function exec_function(string|array|callable $function,array $args=array()): mixed {
        return self::getInstance()->exec_function($function,$args);
    }

    /**
     * 获取当前应用名称
     *
     * - 路由上下文由 `Route` 在分发前写入, 未分发时返回 null
     *
     * @access public
     * @return string|null
     * @throws Exception
     */
    public static function getAppName(): ?string {
        return self::getData('route_info')['app']??null;
    }

    /**
     * 获取当前控制器名称
     *
     * @access public
     * @return string|null
     * @throws Exception
     */
    public static function getControllerName(): ?string {
        return self::getData('route_info')['controller']??null;
    }

    /**
     * 获取当前方法名称
     *
     * @access public
     * @return string|null
     * @throws Exception
     */
    public static function getMethodName(): ?string {
        return self::getData('route_info')['method']??null;
    }

}
