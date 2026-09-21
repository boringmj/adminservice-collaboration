<?php

/**
 * demo 应用路由
 *
 * - 演示路由使用 `any()`: 原约定式路由不区分请求方法, 此处保持一致
 *
 * @var \AdminService\Router\Router $router
 */

// 应用默认控制器
$router->any('/demo',array(\app\demo\controller\Index::class,'index'));

// Autowire
$router->any('/demo/autowire',array(\app\demo\controller\Autowire::class,'index'));
$router->any('/demo/autowire/index',array(\app\demo\controller\Autowire::class,'index'));

// DatabaseDemo
$router->any('/demo/databaseDemo',array(\app\demo\controller\DatabaseDemo::class,'index'));
$router->any('/demo/databaseDemo/index',array(\app\demo\controller\DatabaseDemo::class,'index'));
$router->any('/demo/databaseDemo/demo',array(\app\demo\controller\DatabaseDemo::class,'demo'));
$router->any('/demo/databaseDemo/facade',array(\app\demo\controller\DatabaseDemo::class,'facade'));

// Index
$router->any('/demo/index',array(\app\demo\controller\Index::class,'index'));
$router->any('/demo/index/index',array(\app\demo\controller\Index::class,'index'));
$router->any('/demo/index/request',array(\app\demo\controller\Index::class,'request'));
$router->any('/demo/index/view_demo',array(\app\demo\controller\Index::class,'view_demo'));
$router->any('/demo/index/validator',array(\app\demo\controller\Index::class,'validator'));
$router->any('/demo/index/log',array(\app\demo\controller\Index::class,'log'));
$router->any('/demo/index/exec',array(\app\demo\controller\Index::class,'exec'));
$router->any('/demo/index/upload',array(\app\demo\controller\Index::class,'upload'));
$router->any('/demo/index/curl',array(\app\demo\controller\Index::class,'curl'));

// OrmDemo
$router->any('/demo/ormDemo',array(\app\demo\controller\OrmDemo::class,'index'));
$router->any('/demo/ormDemo/index',array(\app\demo\controller\OrmDemo::class,'index'));
$router->any('/demo/ormDemo/schema',array(\app\demo\controller\OrmDemo::class,'schema'));
$router->any('/demo/ormDemo/relation',array(\app\demo\controller\OrmDemo::class,'relation'));
$router->any('/demo/ormDemo/prefixCheck',array(\app\demo\controller\OrmDemo::class,'prefixCheck'));
$router->any('/demo/ormDemo/transaction',array(\app\demo\controller\OrmDemo::class,'transaction'));
$router->any('/demo/ormDemo/addUpdatedAt',array(\app\demo\controller\OrmDemo::class,'addUpdatedAt'));
