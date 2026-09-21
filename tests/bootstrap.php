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

/**
 * 测试环境函数库加载
 *
 * - 与 Main::loadFunction() 一致: 按 config/function.php 的 loader 加载公共函数
 * - 控制器助手函数(json / view 等)依赖该加载
 *
 * @return void
 */
function load_test_functions(): void {
    $loader=Config::get('function.loader');
    if(!is_array($loader))
        return;
    foreach($loader as $function) {
        $file=Config::get('function.path').'/'.$function.'.php';
        if(is_file($file))
            include_once $file;
    }
}

// 初始化测试配置与函数库
load_test_config();
load_test_functions();
