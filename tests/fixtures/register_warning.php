<?php

/**
 * 注册错误处理器后警告收集夹具
 *
 * - 模拟入口在注册前把 error_reporting 设为 0
 * - 注册后普通警告仍应被收集并走错误出口
 */

require dirname(__DIR__,2).'/vendor/autoload.php';

use AdminService\Error;

error_reporting(0);
Error::register(null,false);
trigger_error('registered warning',E_USER_WARNING);
