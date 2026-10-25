<?php

/**
 * 原生会话未开始时修改 Session ID 的子进程用例
 *
 * - 必须在独立进程中执行: PHPUnit 已输出内容后, PHP 8.4 的 session_id() 会返回 false
 * - 子进程无输出时执行, 验证 setId 同步 PHP 原生会话 ID
 */

require dirname(__DIR__,2).'/vendor/autoload.php';

use AdminService\NativeSession;

$session=new NativeSession();
$session->setId('nativefixedid123');

echo '|GET='.$session->getId();
echo '|SID='.session_id();
