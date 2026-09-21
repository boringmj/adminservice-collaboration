<?php

/**
 * 集中式路由定义
 *
 * - 由 {@see \AdminService\Router\Router::load()} 引入, 可直接使用变量 `$router`
 * - 路径中的 `{name}` 与 `{name:约束}` 为参数占位符, 如 `/user/{id:\d+}`
 * - 演示路由使用 `any()`: 原约定式路由不区分请求方法, 此处保持一致
 *
 * @var \AdminService\Router\Router $router
 */

// index 应用
$router->any('/',array(\app\index\controller\Index::class,'index'));
$router->any('/index/Index/index',array(\app\index\controller\Index::class,'index'));

// demo 应用: Autowire
$router->any('/demo/Autowire/index',array(\app\demo\controller\Autowire::class,'index'));

// demo 应用: DatabaseDemo
$router->any('/demo/DatabaseDemo/index',array(\app\demo\controller\DatabaseDemo::class,'index'));
$router->any('/demo/DatabaseDemo/demo',array(\app\demo\controller\DatabaseDemo::class,'demo'));
$router->any('/demo/DatabaseDemo/facade',array(\app\demo\controller\DatabaseDemo::class,'facade'));

// demo 应用: Index
$router->any('/demo/Index/index',array(\app\demo\controller\Index::class,'index'));
$router->any('/demo/Index/request',array(\app\demo\controller\Index::class,'request'));
$router->any('/demo/Index/view_demo',array(\app\demo\controller\Index::class,'view_demo'));
$router->any('/demo/Index/validator',array(\app\demo\controller\Index::class,'validator'));
$router->any('/demo/Index/log',array(\app\demo\controller\Index::class,'log'));
$router->any('/demo/Index/exec',array(\app\demo\controller\Index::class,'exec'));
$router->any('/demo/Index/upload',array(\app\demo\controller\Index::class,'upload'));
$router->any('/demo/Index/curl',array(\app\demo\controller\Index::class,'curl'));

// demo 应用: OrmDemo
$router->any('/demo/OrmDemo/index',array(\app\demo\controller\OrmDemo::class,'index'));
$router->any('/demo/OrmDemo/schema',array(\app\demo\controller\OrmDemo::class,'schema'));
$router->any('/demo/OrmDemo/relation',array(\app\demo\controller\OrmDemo::class,'relation'));
$router->any('/demo/OrmDemo/prefixCheck',array(\app\demo\controller\OrmDemo::class,'prefixCheck'));
$router->any('/demo/OrmDemo/transaction',array(\app\demo\controller\OrmDemo::class,'transaction'));
$router->any('/demo/OrmDemo/addUpdatedAt',array(\app\demo\controller\OrmDemo::class,'addUpdatedAt'));
