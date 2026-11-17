<?php

/**
 * 原生会话显式采用指定 ID 的夹具
 *
 * - 调用方先 setId() 再 init(), 严格模式不应替换该 ID
 */

require dirname(__DIR__,2).'/vendor/autoload.php';

use AdminService\NativeSession;

$session=new NativeSession();
$session->setId('adoptfixedid123');
$session->init();
echo '|SID='.session_id();
echo '|GET='.$session->getId();
$session->close();
