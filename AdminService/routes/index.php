<?php

/**
 * index 应用路由
 *
 * - 路径参数按名注入控制器方法形参, 如 `{name}` 对应 index(string $name)
 * - `/index/index/index` 由控制器上的 `#[Route]` 属性声明, 不在此处注册(见 app\index\controller\Index)
 *
 * @var \AdminService\Router\Router $router
 */

$router->any('/',array(\app\index\controller\Index::class,'index'));
$router->any('/index',array(\app\index\controller\Index::class,'index'));
$router->any('/index/index',array(\app\index\controller\Index::class,'index'));
$router->any('/index/index/index/{name}',array(\app\index\controller\Index::class,'index'));
