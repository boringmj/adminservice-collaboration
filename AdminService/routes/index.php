<?php

/**
 * index 应用路由
 *
 * - 路径参数按名注入控制器方法形参, 如 `{name}` 对应 index(string $name)
 *
 * @var \AdminService\Router\Router $router
 */

$router->any('/',array(\app\index\controller\Index::class,'index'));
$router->any('/index',array(\app\index\controller\Index::class,'index'));
$router->any('/index/Index',array(\app\index\controller\Index::class,'index'));
$router->any('/index/Index/index',array(\app\index\controller\Index::class,'index'));
$router->any('/index/Index/index/{name}',array(\app\index\controller\Index::class,'index'));
