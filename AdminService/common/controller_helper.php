<?php

namespace AdminService\common;

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
    $method->setAccessible(true);
    return $method->invoke($controller,$template,$data);
}

/**
 * 设置输出类型为json
 *
 * @param mixed $data 数据
 * @param int $code 状态码
 * @return mixed
 */
function json(mixed $data=null,int $code=200): mixed {
    $response=App::get(Response::class);
    $response->status($code);
    return $response->json($data);
}