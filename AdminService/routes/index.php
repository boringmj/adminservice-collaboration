<?php

/**
 * index 应用路由
 *
 * @var \AdminService\Router\Router $router
 */

$router->any('/',array(\app\index\controller\Index::class,'index'));
$router->any('/index',array(\app\index\controller\Index::class,'index'));
$router->any('/index/Index',array(\app\index\controller\Index::class,'index'));
$router->any('/index/Index/index',array(\app\index\controller\Index::class,'index'));
