<?php

namespace AdminService\common;

use base\Request;
use AdminService\App;
use AdminService\Exception;
use \ReflectionException;

/**
 * 获取参数
 *
 * @param int|string $param 参数
 * @param mixed $default 默认值
 * @return mixed
 * @throws Exception|ReflectionException
 */
function param(int|string $param,mixed $default=null): mixed {
    return App::get(Request::class)->get($param,$default);
}