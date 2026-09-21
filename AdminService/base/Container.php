<?php

namespace base;

use ReflectionClass;

/**
 * 容器契约
 *
 * - 容器内核(绑定表 / 实例表 / 反射缓存 / 参数转换开关)**全部是实例状态**, 不再有静态状态
 * - 实现见 `AdminService\Container`(实现层), 门面见 `AdminService\App`(使用者入口, 无状态转发)
 * - 契约层不得引用实现层: 本文件只依赖 `base\` 自身与语言内置
 * - 契约只保留**实证在用的入口**: 无调用点的批量登记 / 反射缓存方法已随实现拆分删除(见纲领 4.5);改名与新增(`singleton` / `alias` / `fresh` / `has`)留待 API 收敛那一步
 *
 * @access public
 * @package base
 * @version 1.0.0
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
     * 获取对象(不存在则自动实例化, 并复用容器中已存在的实例)
     *
     * @access public
     * @param string $name 对象名(类名或别名)
     * @return object
     * @throws \Exception
     */
    public function get(string $name): object;

    /**
     * 登记一个已实例化对象(会覆盖同名的绑定)
     *
     * @access public
     * @param string $name 对象名
     * @param object $object 对象
     * @return void
     */
    public function set(string $name,object $object): void;

    /**
     * 获取真实类名(解析别名与绑定的嵌套, 支持递归)
     *
     * @access public
     * @param string $name 别名或类名
     * @param bool $recursive 是否递归解析
     * @param int $max_depth 最大递归深度
     * @return string
     * @throws \Exception
     */
    public function getRealClass(string $name,bool $recursive=true,int $max_depth=255): string;

    /**
     * 为抽象类或接口绑定实现类(会覆盖已存在的绑定或别名)
     *
     * @access public
     * @param string $name 别名或抽象类或接口名
     * @param string $class 目标类名
     * @return void
     * @throws \Exception
     */
    public function setClass(string $name,string $class): void;

    /**
     * 为抽象类或接口绑定实现类(语义同 `setClass`, 对齐主流命名)
     *
     * @access public
     * @param string $abstract 抽象类或接口名(或别名)
     * @param string $concrete 实现类
     * @return void
     * @throws \Exception
     */
    public function bind(string $abstract,string $concrete): void;

    /**
     * 判断绑定是否会形成循环
     *
     * @access public
     * @param string $abstract 抽象类或接口名
     * @param string $concrete 实现类
     * @return bool
     */
    public function isCircular(string $abstract,string $concrete): bool;

    /**
     * 批量设置或添加未被实例化的类
     *
     * @access public
     * @param array<string,string> $classes 类数组
     * @return void
     * @throws \Exception
     */
    public function setClassByArray(array $classes): void;

    /**
     * 获取全局数据
     *
     * @access public
     * @param string $name 数据名
     * @param mixed $default 默认值
     * @return mixed
     */
    public function getData(string $name,mixed $default=null): mixed;

    /**
     * 设置或添加全局数据
     *
     * @access public
     * @param string $name 数据名
     * @param mixed $data 数据
     * @return void
     */
    public function setData(string $name,mixed $data): void;

    /**
     * 获取对象(容器中已有且未强制新建则复用, 否则实例化并自动装配)
     *
     * @access public
     * @param string $name 对象名(类名或别名)
     * @param bool $is_force 是否强制新建
     * @return object
     * @throws \Exception
     */
    public function make(string $name,bool $is_force=false): object;

    /**
     * 新建对象(每次都是新实例, 并自动装配)
     *
     * @access public
     * @param string $__name 类名或别名
     * @param mixed ...$args 构造函数参数
     * @return object
     * @throws \Exception
     */
    public function new(string $__name,...$args): object;

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
