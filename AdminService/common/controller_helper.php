<?php

namespace AdminService\common;

use base\Response;
use AdminService\App;
use AdminService\Exception;
use \ReflectionClass;
use \ReflectionException;

/**
 * 显示视图
 *
 * @param string|array|null $template 视图名称或数据(如果传入数组则为数据)
 * @param array $data 数据
 * @return string
 * @throws ReflectionException|Exception
 */
function view(null|string|array $template=null,array $data=array()): string {
    $reflector=new ReflectionClass(App::get('Controller'));
    $method=$reflector->getMethod('view');
    $method->setAccessible(true);
    return $method->invoke(App::get('Controller'),$template,$data);
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
    $response->setStatusCode($code);
    return $response->json($data);
}