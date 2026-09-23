<?php

require __DIR__.'/../vendor/autoload.php';

use AdminService\App;
use AdminService\Config;
use AdminService\Config\Loader;
use AdminService\Config\Repository;
use AdminService\Container;
use AdminService\Database\DatabaseConfig;
use base\Database\Db as BaseDb;

/**
 * 装配框架自带目录(`AdminService/config`)的配置,并设为当前配置
 *
 * - 复刻 `Main::init()` 的装配步骤(那边用 `__DIR__.'/config'`, 这里从 `tests/` 上溯)
 * - 用例的 setUp / tearDown 用它把配置重置回真实配置
 *
 * @return void
 */
function load_framework_config(): void {
    $loader=new Loader(dirname(__DIR__).'/AdminService/config');
    Config::setRepository(new Repository($loader->load(),$loader->diagnostics(),env_snapshot()));
}

/**
 * 测试环境配置加载
 *
 * - 装配真实配置后强制关闭 app.debug: 负向用例抛出的预期异常不再于构造时写日志
 * - 生产/开发环境依赖全局兜底(Error::renderAndExit)记录未捕获异常
 *
 * @return void
 */
function load_test_config(): void {
    load_framework_config();
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
 *   不在引导期调用 App::init(), 以免把配置别名提前注入而改变既有用例的解析路径
 * - 数据库层(契约层)不读全局配置, 这里按框架引导的做法安装配置提供者
 * - 需要配置绑定的用例请自行调用 App::init()
 *
 * @return void
 */
function boot_test_container(): void {
    if(!App::hasInstance())
        App::setInstance(new Container());
    // 登记配置实例: 容器内核的参数解析器按 `base\ConfigInterface` 取配置(不再经静态门面),
    // 因此测试容器也要有这一份, 否则 `#[Config]` 一类的注入会静默拿到默认值
    $container=App::getInstance();
    if(!$container->hasInstance(\base\ConfigInterface::class))
        $container->instance(\base\ConfigInterface::class,Config::repository()??new Repository(array()));
    // 注意: **不**给 DatabaseConfig 注入配置实例 —— 测试靠 `Config::set()` 反复换配置来构造场景,
    // 注入会把配置定格成快照, 换配置就影响不到它(见 ConfigFacadeTest 的 set() 同步用例)
    BaseDb::setConfig(new DatabaseConfig());
}

boot_test_container();
