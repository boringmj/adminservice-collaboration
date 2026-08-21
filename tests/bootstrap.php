<?php

require __DIR__.'/../vendor/autoload.php';

use AdminService\Config;

/**
 * 测试环境配置加载
 *
 * - 加载真实配置后强制关闭 app.debug: 负向用例抛出的预期异常不再于构造时写日志
 * - 生产/开发环境依赖全局兜底(Error::renderAndExit)记录未捕获异常
 *
 * @return void
 */
function load_test_config(): void {
    Config::load();
    $configs=Config::all();
    $configs['app']['debug']=false;
    Config::set($configs);
}

// 初始化测试配置
load_test_config();
