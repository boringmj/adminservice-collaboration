<?php

require __DIR__.'/../vendor/autoload.php';

use AdminService\App;
use AdminService\Config;
use AdminService\Container;
use AdminService\Database\DatabaseConfig;
use base\Database\Db as BaseDb;

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

/**
 * 测试进程的引导
 *
 * - 容器改为实例状态后, 门面需要一个"当前容器"才能工作; 这里装一个空的应用级容器,
 *   与旧版"全局静态容器初值(空)"等价 —— 不调用 App::init(), 以免把配置别名提前注入而改变既有用例的解析路径
 * - 数据库层(契约层)不读全局配置, 这里按框架引导的做法安装配置提供者
 * - 需要配置绑定的用例请自行调用 App::init()
 *
 * @return void
 */
function boot_test_container(): void {
    if(!App::hasInstance())
        App::setInstance(new Container());
    BaseDb::setConfig(new DatabaseConfig());
}

boot_test_container();
