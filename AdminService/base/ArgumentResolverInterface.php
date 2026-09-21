<?php

namespace base;

use ReflectionParameter;

/**
 * 参数解析契约
 *
 * - 职责: 参数合并(按名 / 按位 / 可变参数)、类型校验与转换、类型标准化、按签名调用
 * - 实现见 `AdminService\ArgumentResolver`; 控制器 / 管道 / 路由等按**契约**依赖它
 * - 契约层不得引用实现层
 *
 * @access public
 * @package base
 * @version 1.0.0
 */
interface ArgumentResolverInterface {

    /**
     * 整理和合并参数
     *
     * @access public
     * @param ReflectionParameter[] $params 参数
     * @param array<mixed> $args 参数
     * @return array
     * @throws \Exception
     */
    public function merge(array $params,array $args): array;

    /**
     * 判断参数是否符合预期类型
     *
     * @access public
     * @param mixed $arg 参数
     * @param array<string> $types 预期类型
     * @return bool
     */
    public function isValidType(mixed $arg,array $types): bool;

    /**
     * 将参数转换为目标类型
     *
     * @access public
     * @param mixed $value 实参值
     * @param array<string> $types 目标类型列表
     * @return mixed
     */
    public function castParam(mixed $value,array $types): mixed;

    /**
     * 将一个 PHP 类型转为 gettype() 返回的类型
     *
     * @access public
     * @param string $type 类型
     * @return string
     */
    public function getStandardType(string $type): string;

    /**
     * 将一组 PHP 类型转为 gettype() 返回的类型
     *
     * @access public
     * @param array<string> $types 类型数组
     * @return array
     */
    public function getStandardTypes(array $types): array;

    /**
     * 调用对象的方法(参数按签名合并)
     *
     * @access public
     * @param object $object 对象
     * @param string $method 方法名
     * @param array<mixed> $args 参数
     * @return mixed
     * @throws \Exception
     */
    public function call(object $object,string $method,array $args=array()): mixed;

    /**
     * 调用函数(参数按签名合并)
     *
     * @access public
     * @param string|callable $function 函数名或闭包
     * @param array<mixed> $args 参数
     * @return mixed
     * @throws \Exception
     */
    public function callFunction(string|callable $function,array $args=array()): mixed;

}
