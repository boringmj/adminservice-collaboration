<?php

namespace AdminService\common;

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
 * 设置输出类型为json,返回标准json数组
 *
 * @param int|null $code 状态码
 * @param string|null $msg 提示信息
 * @param mixed $data 数据
 * @return array
 * @throws ReflectionException|Exception
 */
function json(?int $code=null,?string $msg=null,mixed $data=null): array {
    $ref=new ReflectionClass(App::get('Controller'));
    $method=$ref->getMethod('type');
    $method->setAccessible(true);
    $method->invoke(App::get('Controller'),'json');
    $return_data=array();
    if($code!==null)
        $return_data['code']=$code;
    if($msg!==null)
        $return_data['msg']=$msg;
    if($data!==null)
        $return_data['data']=$data;
    return $return_data;
}