<?php

/**
 * shutdown 回调顺序夹具
 *
 * - 参数 1: 标记文件路径
 * - 参数 2: normal 正常结束 / warning 已收集的警告
 * - 错误处理器先注册, 标记回调后注册; 若错误出口调用 exit(), 标记回调会被跳过
 */

require dirname(__DIR__,2).'/vendor/autoload.php';

use AdminService\Error;

$marker=$argv[1]??'';
$mode=$argv[2]??'exception';

Error::register(null,false);
register_shutdown_function(function() use ($marker): void {
    if($marker!=='')
        file_put_contents($marker,'ok');
});

if($mode==='warning')
    trigger_error('shutdown warning',E_USER_WARNING);
