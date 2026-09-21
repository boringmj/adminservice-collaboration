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
$router->any('/demo/Autowire',array(\app\demo\controller\Autowire::class,'index'));
$router->any('/demo/Autowire/index',array(\app\demo\controller\Autowire::class,'index'));

// DatabaseDemo
$router->any('/demo/DatabaseDemo',array(\app\demo\controller\DatabaseDemo::class,'index'));
$router->any('/demo/DatabaseDemo/index',array(\app\demo\controller\DatabaseDemo::class,'index'));
$router->any('/demo/DatabaseDemo/demo',array(\app\demo\controller\DatabaseDemo::class,'demo'));
$router->any('/demo/DatabaseDemo/facade',array(\app\demo\controller\DatabaseDemo::class,'facade'));

// Index
$router->any('/demo/Index',array(\app\demo\controller\Index::class,'index'));
$router->any('/demo/Index/index',array(\app\demo\controller\Index::class,'index'));
$router->any('/demo/Index/request',array(\app\demo\controller\Index::class,'request'));
$router->any('/demo/Index/view_demo',array(\app\demo\controller\Index::class,'view_demo'));
$router->any('/demo/Index/validator',array(\app\demo\controller\Index::class,'validator'));
$router->any('/demo/Index/log',array(\app\demo\controller\Index::class,'log'));
$router->any('/demo/Index/exec',array(\app\demo\controller\Index::class,'exec'));
$router->any('/demo/Index/upload',array(\app\demo\controller\Index::class,'upload'));
$router->any('/demo/Index/curl',array(\app\demo\controller\Index::class,'curl'));

// OrmDemo
$router->any('/demo/OrmDemo',array(\app\demo\controller\OrmDemo::class,'index'));
$router->any('/demo/OrmDemo/index',array(\app\demo\controller\OrmDemo::class,'index'));
$router->any('/demo/OrmDemo/schema',array(\app\demo\controller\OrmDemo::class,'schema'));
$router->any('/demo/OrmDemo/relation',array(\app\demo\controller\OrmDemo::class,'relation'));
$router->any('/demo/OrmDemo/prefixCheck',array(\app\demo\controller\OrmDemo::class,'prefixCheck'));
$router->any('/demo/OrmDemo/transaction',array(\app\demo\controller\OrmDemo::class,'transaction'));
$router->any('/demo/OrmDemo/addUpdatedAt',array(\app\demo\controller\OrmDemo::class,'addUpdatedAt'));
