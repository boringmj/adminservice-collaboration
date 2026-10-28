<?php

/**
 * 错误出口 500 兜底夹具
 *
 * - 没有容器/响应对象, 错误页仍须把状态码兜底为 500
 */

require dirname(__DIR__,2).'/vendor/autoload.php';

use AdminService\Error;

Error::register(null,false);
trigger_error('status fallback test',E_USER_WARNING);
Error::handleShutdown();
echo '|STATUS|'.http_response_code();
