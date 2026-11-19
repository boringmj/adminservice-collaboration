<?php

namespace AdminService\common;

use base\Request;
use base\Response;
use base\RouteContextInterface;
use AdminService\App;
use AdminService\Exception;
use ReflectionException;

use function is_string;

/**
 * 显示视图
 *
 * @param string|array|null $template 视图名称或数据(如果传入数组则为数据)
 * @param array<string,mixed> $data 数据
 * @return string
 * @throws ReflectionException|Exception
 */
function view(null|string|array $template=null,array $data=array()): string {
    $container=App::getInstance();
    // 路由上下文属请求级对象: 仅分发中可用
    $context=$container->hasInstance(RouteContextInterface::class)?$container->get(RouteContextInterface::class):null;
    $controller_class=$context instanceof RouteContextInterface?$context->controllerClass():null;
    if(!is_string($controller_class))
        throw new Exception('无法定位当前控制器: 路由上下文不可用(仅可在控制器内调用 view())');
    $controller=App::get($controller_class);
    $reflector=App::getReflectionByObject($controller);
    $method=$reflector->getMethod('view');
    return $method->invoke($controller,$template,$data);
}

/**
 * 设置输出类型为json
 *
 * - 仅请求处理内可用: 当前容器没有请求级 `Response` 实例时抛异常, 不回落应用级容器自动装配
 *
 * @param mixed $data 数据
 * @param int|null $code 状态码(null = 不改动已设状态码)
 * @return mixed
 * @throws Exception 请求处理外调用
 */
function json(mixed $data=null,?int $code=null): mixed {
    $container=App::getInstance();
    if(!$container->hasInstance(Response::class))
        throw new Exception('无法获取当前响应: 请求级 Response 实例不可用(仅可在请求处理内调用 json())');
    $response=$container->get(Response::class);
    if($code!==null)
        $response->status($code);
    return $response->json($data);
}

/**
 * 获取参数
 *
 * - 仅请求处理内可用: 当前容器没有请求级 `Request` 实例时抛异常, 不回落应用级容器自动装配
 *
 * @param int|string $param 参数
 * @param mixed $default 默认值
 * @return mixed
 * @throws Exception 请求处理外调用
 */
function param(int|string $param,mixed $default=null): mixed {
    $container=App::getInstance();
    if(!$container->hasInstance(Request::class))
        throw new Exception('无法获取当前请求: 请求级 Request 实例不可用(仅可在请求处理内调用 param())');
    return $container->get(Request::class)->param((string)$param,Request::ALL_PARAM,$default);
}
