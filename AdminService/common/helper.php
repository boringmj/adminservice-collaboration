<?php

namespace AdminService\common;

use AdminService\App;

/**
 * 获取参数
 * 
 * @param int|string $param 参数
 * @param mixed $default 默认值
 * @return mixed
 */
function param(int|string $param,mixed $default=null): mixed {
    return App::get('Request')->get($param,$default);
}

?>