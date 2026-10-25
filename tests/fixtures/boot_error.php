<?php

/**
 * 引导期错误路径夹具
 *
 * - 只加载自动加载器, 不启动测试 bootstrap / 应用容器
 * - 模拟 `Main::init()` 早期: 错误处理器已注册, 但初始化尚未完成
 * - 参数 1: 回落日志文件路径
 */

require dirname(__DIR__,2).'/vendor/autoload.php';

use AdminService\Error;

$log_file=$argv[1]??'';
if($log_file!=='')
    ini_set('error_log',$log_file);

Error::register(null,false);

throw new RuntimeException('引导前错误');
