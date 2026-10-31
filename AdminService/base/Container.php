<?php

namespace base;

use Closure;
use ReflectionClass;

/**
 * 容器契约
 *
 * - 容器内核(实例表 / 绑定表 / 别名表 / 单例表 / 闭包工厂表 / 参数转换开关)**全部是实例状态**, 没有静态状态
 * - 实现见 `AdminService\Container`(实现层), 门面见 `AdminService\App`(使用者入口, 无状态转发)
 * - 契约层不得引用实现层: 本文件只依赖 `base\` 自身与语言内置
 * - 解析语义: `get()` / `make()` **复用已登记实例**;要每次新建用 `fresh()`;要带构造参数用 `build()`
 * - 契约只保留**实证在用的入口**;`resolve()` / 循环检测等属实现细节, 不在此列
 *
 * @access public
 * @package base
 * @version 2.0.0
 */
interface Container {

    /**
     * 设置是否允许标量参数静默转换
     *
     * @access public
     * @param bool $enable 是否允许
     * @return void
     */
    public function setParamCast(bool $enable): void;

    /**
     * 获取是否允许标量参数静默转换
     *
     * @access public
     * @return bool
     */
    public function getParamCast(): bool;

    /**
     * 通过已有对象获取反射类对象(会缓存结果)
     *
     * @access public
     * @param object $object 对象
     * @return ReflectionClass
     */
    public function getReflectionByObject(object $object): ReflectionClass;

    /**
     * 获取对象(存在则复用, 不存在则实例化并复用)
     *
     * @access public
     * @template T of object
     * @param class-string<T>|string $name 对象名(类名 / 接口名 / 别名)
     * @return T
     * @throws \Exception
     */
    public function get(string $name): object;

    /**
     * 判断容器能否定位到该名称的可实例化类
     *
     * - 先解析别名 / 绑定, 再按结果查实例与已声明类
     * - 只看实例 / 绑定 / 别名与已声明类, 不检查构造参数能否解析
     *
     * @access public
     * @param string $name 名称(类名 / 接口名 / 别名)
     * @return bool
     */
    public function has(string $name): bool;

    /**
     * 登记一个已实例化对象(按真实类名存储)
     *
     * @access public
     * @param string $name 对象名(类名 / 接口名 / 别名)
     * @param object $object 对象
     * @return void
     */
    public function instance(string $name,object $object): void;

    /**
     * 为抽象类或接口绑定实现类或闭包工厂
     *
     * - 字符串实现类: 解析结果按真实类名复用
     * - 闭包工厂: 首次解析后缓存, 后续 `get()` / `make()` 复用; `fresh()` 重新调用并替换
     *   工厂签名 `function(Container $container): object`
     * - 每次都创建新对象请使用 `factory()`
     *
     * @access public
     * @param string $abstract 抽象类或接口名
     * @param string|Closure $concrete 实现类或工厂
     * @return void
     * @throws \Exception
     */
    public function bind(string $abstract,string|Closure $concrete): void;

    /**
     * 为名称设置别名(与绑定分表, 支持嵌套与链式解析)
     *
     * @access public
     * @param string $alias 别名
     * @param string $abstract 目标名称(类名 / 接口名 / 另一个别名)
     * @return void
     * @throws \Exception
     */
    public function alias(string $alias,string $abstract): void;

    /**
     * 单例绑定:解析后抽象名与实现类名指向同一实例
     *
     * - 字符串实现类: 与现有类绑定一致
     * - 闭包工厂: 首次 `get()` / `make()` 调用后缓存, 后续复用; `fresh()` 重新调用并替换
     *   工厂签名 `function(Container $container): object`
     *
     * @access public
     * @param string $abstract 抽象类或接口名
     * @param string|Closure $concrete 实现类或工厂
     * @return void
     * @throws \Exception
     */
    public function singleton(string $abstract,string|Closure $concrete): void;

    /**
     * 瞬态闭包工厂绑定
     *
     * - 每次 `get()` / `make()` / `fresh()` 都调用工厂, 不写实例表
     * - 工厂签名 `function(Container $container): object`
     *
     * @access public
     * @param string $abstract 抽象类或接口名(或任意绑定名)
     * @param Closure $concrete 工厂
     * @return void
     * @throws \Exception
     */
    public function factory(string $abstract,Closure $concrete): void;

    /**
     * 批量绑定(抽象 → 实现类或闭包工厂)
     *
     * @access public
     * @param array<string,string|Closure> $bindings 绑定表(键为抽象名, 值为实现类或工厂)
     * @return void
     * @throws \Exception
     */
    public function bindAll(array $bindings): void;

    /**
     * 派生一个请求级子容器
     *
     * - 绑定 / 别名 / 单例表 / 闭包工厂表在派生时**复制快照**, 之后父容器的变更不回溯
     * - 反射缓存共享; 实例表独立(不回流应用级); 父容器已登记实例可被子容器读取
     *
     * @access public
     * @return static
     */
    public function fork(): static;

    /**
     * 清空实例表(绑定 / 别名 / 单例表与反射缓存保留)
     *
     * - 请求级容器在下一次请求开始时被清空
     *
     * @access public
     * @return void
     */
    public function reset(): void;

    /**
     * 判断"实例是否已登记"(自身或父容器)
     *
     * - 与 `has()` 的区别: 只看**实例表**, 不承诺"容器能构建出来"
     * - 典型用途: 判断请求级对象是否已就位(如路由上下文、响应)
     *
     * @access public
     * @param string $name 名称(类名 / 接口名 / 别名)
     * @return bool
     */
    public function hasInstance(string $name): bool;

    /**
     * 获取对象(复用已登记实例; 未登记则实例化并做完整装配)
     *
     * - 循环依赖被截断时, 回落的实例不做属性 / Setter / 生命周期方法注入;
     *   可选构造参数回落默认值 / null, 无法回落时抛异常
     *
     * @access public
     * @template T of object
     * @param class-string<T>|string $name 对象名(类名 / 接口名 / 别名)
     * @return T
     * @throws \Exception
     */
    public function make(string $name): object;

    /**
     * 强制新建对象(跳过复用, 做完整装配, 结果覆盖登记)
     *
     * - 接口 / 抽象类名同样强制新建查找到的实现类
     * - 结果覆盖解析结果的登记; 接口 / 抽象类名已有实例登记时同步指向新实例
     * - 子容器刷新接口 / 抽象类登记时写入自身, 不修改父容器
     * - 重建容器自身时两个自登记键同步指向新容器
     * - 循环依赖回落的限制与 `make()` 相同
     *
     * @access public
     * @template T of object
     * @param class-string<T>|string $name 对象名(类名 / 接口名 / 别名)
     * @return T
     * @throws \Exception
     */
    public function fresh(string $name): object;

    /**
     * 构建对象(带构造参数, 做完整装配, **本对象不登记**)
     *
     * - 构造参数与属性依赖仍按 `make()` 解析并登记
     * - 构建过程中调用时沿用当前构建栈; 同一类重入抛异常
     * - 闭包工厂绑定不支持 `build()`; 使用 `get()` / `make()` / `fresh()`
     *
     * @access public
     * @template T of object
     * @param class-string<T>|string $__name 对象名(类名 / 接口名 / 别名)
     * @param mixed ...$args 构造函数参数(关系型键按参数名赋值, 索引键按位置赋值)
     * @return T
     * @throws \Exception
     */
    public function build(string $__name,...$args): object;

    /**
     * 执行类或对象的方法
     *
     * @access public
     * @param object|string $object 对象或类名
     * @param string $method 方法名
     * @param array<mixed> $args 方法参数(关系型数组按参数名赋值, 索引数组按位置赋值)
     * @return mixed
     * @throws \Exception
     */
    public function exec_class_function(object|string $object,string $method,array $args=array()): mixed;

    /**
     * 执行函数(支持函数名、闭包与 `array(类或对象, 方法)` 形式)
     *
     * @access public
     * @param string|array|callable $function 函数
     * @param array<mixed> $args 函数参数(关系型数组按参数名赋值, 索引数组按位置赋值)
     * @return mixed
     * @throws \Exception
     */
    public function exec_function(string|array|callable $function,array $args=array()): mixed;

}
