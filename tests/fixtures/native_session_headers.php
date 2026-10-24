<?php

/**
 * 原生会话 headers-sent 夹具
 *
 * - 先输出内容, 再尝试启动原生会话
 * - 期望框架抛出受控异常, 而不是依赖 PHP 警告后继续“假会话”
 */

require dirname(__DIR__,2).'/vendor/autoload.php';

use AdminService\Exception;
use AdminService\NativeSession;

echo 'headers';

try {
    $session=new NativeSession();
    $session->init();
    echo '|NO_THROW';
} catch(Exception $e) {
    echo '|CAUGHT';
}
