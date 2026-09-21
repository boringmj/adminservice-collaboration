<?php

namespace base;

use ReflectionClass;

/**
 * 容器契约
 *
 * - 容器内核(实例表 / 绑定表 / 别名表 / 单例表 / 全局数据 / 参数转换开关)**全部是实例状态**, 没有静态状态
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
     * @param string $name 对象名(类名 / 接口名 / 别名)
     * @return object
     * @throws \Exception
     */
    public function get(string $name): object;

    /**
     * 判断容器能否给出该名称
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
     * 为抽象类或接口绑定实现类(解析结果按真实类名复用)
     *
     * @access public
     * @param string $abstract 抽象类或接口名
     * @param string $concrete 实现类
     * @return void
     * @throws \Exception
     */
    public function bind(string $abstract,string $concrete): void;

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
     * @access public
     * @param string $abstract 抽象类或接口名
     * @param string $concrete 实现类
     * @return void
     * @throws \Exception
     */
    public function singleton(string $abstract,string $concrete): void;

    /**
     * 批量绑定(抽象 → 实现)
     *
     * @access public
     * @param array<string,string> $bindings 绑定表(键为抽象名, 值为实现类)
     * @return void
     * @throws \Exception
     */
    public function bindAll(array $bindings): void;

    /**
     * 派生一个请求级子容器
     *
     * - 共享绑定 / 别名 / 单例表与反射缓存
     * - 实例表与全局数据独立(请求结束丢弃, 不影响应用级)
     *
     * @access public
     * @return static
     */
    public function fork(): static;

    /**
     * 清空实例表与全局数据(绑定 / 别名 / 单例表与反射缓存保留)
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
     * @access public
     * @param string $name 对象名(类名 / 接口名 / 别名)
     * @return object
     * @throws \Exception
     */
    public function make(string $name): object;

    /**
     * 强制新建对象(每次都是新实例, 做完整装配, 结果覆盖登记)
     *
     * @access public
     * @param string $name 对象名(类名 / 接口名 / 别名)
     * @return object
     * @throws \Exception
     */
    public function fresh(string $name): object;

    /**
     * 构建对象(带构造参数, 做完整装配, **不登记**)
     *
     * @access public
     * @param string $__name 对象名(类名 / 接口名 / 别名)
     * @param mixed ...$args 构造函数参数(关系型键按参数名赋值, 索引键按位置赋值)
     * @return object
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
