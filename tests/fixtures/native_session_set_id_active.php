<?php

/**
 * 原生会话已激活时修改 Session ID 夹具
 *
 * - 会话启动后 ID 不可再修改, 期望框架抛受控异常
 * - 同时确认对象属性与原生 ID 不会分叉
 */

require dirname(__DIR__,2).'/vendor/autoload.php';

use AdminService\Exception;
use AdminService\NativeSession;

session_id('activefixedid123');
session_start();

echo '|BEFORE='.session_id();

$session=new NativeSession();
try {
    $session->setId('otherfixedid123');
    echo '|NO_THROW';
} catch(Exception $e) {
    echo '|CAUGHT';
}

echo '|AFTER='.session_id();
echo '|GET='.$session->getId();

session_destroy();
