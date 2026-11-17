<?php

/**
 * 带状态码异常的错误出口夹具
 *
 * - 实现 HttpStatusExceptionInterface 的异常应让错误出口使用其状态码
 */

require dirname(__DIR__,2).'/vendor/autoload.php';

use AdminService\Error;
use AdminService\exception\InvalidInputException;

Error::register(null,false);
Error::handleException(new InvalidInputException('bad input',100401,array('json_error'=>4)));
echo '|STATUS|'.http_response_code();
