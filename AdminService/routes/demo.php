<?php

use AdminService\Router\Router;
use \app\demo\controller\Index;
use \app\demo\controller\Autowire;
use \app\demo\controller\DatabaseDemo;
use \app\demo\controller\OrmDemo;

/**
 * demo 应用路由
 *
 * - 每个控制器一个分组: 分组声明前缀, 组内只写子路径
 * - 演示路由使用 `any()`: 原约定式路由不区分请求方法, 此处保持一致
 */

return function(Router $router): void {

    // 应用默认控制器
    $router->any('/demo',array(Index::class,'index'));

    // 子路径与控制器方法同名, 逐条注册
    $router->group(array('prefix'=>'/demo/autowire'),function(Router $router): void {
        $router->any('',array(Autowire::class,'index'));
        $router->any('/index',array(Autowire::class,'index'));
    });

    $router->group(array('prefix'=>'/demo/databaseDemo'),function(Router $router): void {
        $router->any('',array(DatabaseDemo::class,'index'));
        $router->any('/index',array(DatabaseDemo::class,'index'));
        $router->any('/demo',array(DatabaseDemo::class,'demo'));
        $router->any('/facade',array(DatabaseDemo::class,'facade'));
    });

    $router->group(array('prefix'=>'/demo/index'),function(Router $router): void {
        $router->any('',array(Index::class,'index'));
        // 子路径即方法名, 逐个注册
        foreach(array('index','request','view_demo','validator','log','exec','upload','curl') as $method)
            $router->any('/'.$method,array(Index::class,$method));
    });

    $router->group(array('prefix'=>'/demo/ormDemo'),function(Router $router): void {
        $router->any('',array(OrmDemo::class,'index'));
        // 子路径即方法名, 逐个注册
        foreach(array('index','schema','relation','prefixCheck','transaction','addUpdatedAt') as $method)
            $router->any('/'.$method,array(OrmDemo::class,$method));
    });

};
