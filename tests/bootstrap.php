<?php

require __DIR__.'/../vendor/autoload.php';

use AdminService\App;
use AdminService\Config;
use AdminService\Config\Loader;
use AdminService\Config\Repository;
use AdminService\Container;
use AdminService\Database\DatabaseConfig;
use AdminService\Runtime;
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
    // 与 Main::init() 一致: runtime 根必须在其它配置之前解析
    Runtime::path();
    $loader=new Loader(dirname(__DIR__).'/AdminService/config');
    $config=new Repository($loader->load(),\AdminService\env_snapshot());
    // 默认 Runtime 指向项目 runtime 时, 编译缓存隔离到临时目录, 避免用例写入项目缓存
    if(test_is_inside_project(Runtime::path(),dirname(__DIR__)))
        $config->put('cache.path',test_cache_root());
    date_default_timezone_set((string)$config->get('app.timezone','Asia/Shanghai'));
    Config::setRepository($config);
}

/**
 * 判断路径是否位于项目目录内
 *
 * - 相对路径按当前工作目录解析; Windows 下按小写比较
 *
 * @param string $path 路径
 * @param string $project 项目根目录
 * @return bool
 */
function test_is_inside_project(string $path,string $project): bool {
    $path=test_absolute_path($path);
    $project=test_absolute_path($project);
    if(DIRECTORY_SEPARATOR==='\\') {
        $path=strtolower($path);
        $project=strtolower($project);
    }
    return $path===$project||str_starts_with($path.'/',$project.'/');
}

/**
 * 解析为规范化的绝对路径
 *
 * @param string $path 路径
 * @return string
 */
function test_absolute_path(string $path): string {
    $real=realpath($path);
    $path=str_replace('\\','/',$real!==false?$real:$path);
    if(preg_match('#^(?:[A-Za-z]:/|/)#',$path)!==1)
        $path=str_replace('\\','/',(string)getcwd()).'/'.$path;
    return rtrim($path,'/');
}

/**
 * 测试进程的编译缓存根目录
 *
 * - 首次调用时生成唯一临时目录, 进程退出时递归删除
 *
 * @return string
 */
function test_cache_root(): string {
    static $root=null;
    if($root!==null)
        return $root;
    $base=str_replace('\\','/',sys_get_temp_dir()).'/adminservice_test_cache_'.getmypid().'_'.uniqid('',true);
    register_shutdown_function(static function() use($base): void {
        test_remove_path($base);
    });
    return $root=$base.'/cache';
}

/**
 * 递归删除文件或目录
 *
 * @param string $path 路径
 * @return void
 */
function test_remove_path(string $path): void {
    if(is_file($path)||is_link($path)) {
        @unlink($path);
        return;
    }
    if(!is_dir($path))
        return;
    $entries=scandir($path);
    if($entries!==false) {
        foreach($entries as $entry)
            if($entry!=='.'&&$entry!=='..')
                test_remove_path($path.'/'.$entry);
    }
    @rmdir($path);
}

/**
 * 用一份代码里的配置树安装当前配置(`Config::new()` 只构建, 安装是显式的一步)
 *
 * @param array<string,mixed> $configs 配置树
 * @return Repository 安装上去的实例
 */
function set_config(array $configs): Repository {
    $repository=Config::new($configs);
    Config::setRepository($repository);
    return $repository;
}

/**
 * 测试环境配置加载
 *
 * - 装配真实配置后强制关闭 app.debug: 负向用例抛出的预期异常不再于构造时写日志
 * - 生产/开发环境依赖全局异常出口(Error::renderAndExit)记录未捕获异常
 *
 * @return void
 */
function load_test_config(): void {
    load_framework_config();
    $configs=Config::all();
    $configs['app']['debug']=false;
    set_config($configs);
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
    // 按**真实容器的布置**装配置: 实例只登记在实现类名下, "契约名 → 实现类"走别名(同 `config/app.php`),
    // 内核的参数解析器按契约名取配置时靠这条别名解析过去(不再经静态门面)
    $container=App::getInstance();
    if(!$container->hasInstance(Repository::class))
        $container->instance(Repository::class,Config::repository()??new Repository(array()));
    foreach((array)Config::get('app.alias',array()) as $alias=>$abstract)
        if(!is_int($alias))
            $container->alias((string)$alias,(string)$abstract);
    // 注意: **不**给 DatabaseConfig 注入配置实例 —— 测试靠 `Config::new()` 反复换配置来构造场景,
    // 注入会固定使用该配置实例, 门面换新实例不影响它; 同一实例的修改仍可见(见 ConfigFacadeTest 的 set() 同步用例)
    BaseDb::setConfig(new DatabaseConfig());
}

boot_test_container();
