<?php

use AdminService\Router\Router;
use \app\demo\controller\Index;
use \app\demo\controller\Autowire;
use \app\demo\controller\DatabaseDemo;
use \app\demo\controller\OrmDemo;

/**
 * demo 应用路由
 *
 * - 演示路由使用 `any()`: 原约定式路由不区分请求方法, 此处保持一致
 */

return function(Router $router): void {

    // 应用默认控制器
    $router->any('/demo',array(Index::class,'index'));

    // Autowire
    $router->any('/demo/autowire',array(Autowire::class,'index'));
    $router->any('/demo/autowire/index',array(Autowire::class,'index'));

    // DatabaseDemo
    $router->any('/demo/databaseDemo',array(DatabaseDemo::class,'index'));
    $router->any('/demo/databaseDemo/index',array(DatabaseDemo::class,'index'));
    $router->any('/demo/databaseDemo/demo',array(DatabaseDemo::class,'demo'));
    $router->any('/demo/databaseDemo/facade',array(DatabaseDemo::class,'facade'));

    // Index
    $router->any('/demo/index',array(Index::class,'index'));
    $router->any('/demo/index/index',array(Index::class,'index'));
    $router->any('/demo/index/request',array(Index::class,'request'));
    $router->any('/demo/index/view_demo',array(Index::class,'view_demo'));
    $router->any('/demo/index/validator',array(Index::class,'validator'));
    $router->any('/demo/index/log',array(Index::class,'log'));
    $router->any('/demo/index/exec',array(Index::class,'exec'));
    $router->any('/demo/index/upload',array(Index::class,'upload'));
    $router->any('/demo/index/curl',array(Index::class,'curl'));

    // OrmDemo
    $router->any('/demo/ormDemo',array(OrmDemo::class,'index'));
    $router->any('/demo/ormDemo/index',array(OrmDemo::class,'index'));
    $router->any('/demo/ormDemo/schema',array(OrmDemo::class,'schema'));
    $router->any('/demo/ormDemo/relation',array(OrmDemo::class,'relation'));
    $router->any('/demo/ormDemo/prefixCheck',array(OrmDemo::class,'prefixCheck'));
    $router->any('/demo/ormDemo/transaction',array(OrmDemo::class,'transaction'));
    $router->any('/demo/ormDemo/addUpdatedAt',array(OrmDemo::class,'addUpdatedAt'));

};
