<?php

/**
 * 原生会话加固配置夹具
 *
 * - CLI 不支持 `session_regenerate_id()`, 轮换成功路径需在真实 SAPI 下验证
 */

require dirname(__DIR__,2).'/vendor/autoload.php';

use AdminService\NativeSession;

$session=new NativeSession();
$session->init();
echo '|STRICT|'.ini_get('session.use_strict_mode');
echo '|ONLYCOOKIES|'.ini_get('session.use_only_cookies');
echo '|HTTPONLY|'.ini_get('session.cookie_httponly');
echo '|SAMESITE|'.ini_get('session.cookie_samesite');
$session->close();
