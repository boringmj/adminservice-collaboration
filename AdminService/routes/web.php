<?php

/**
 * 集中式路由定义
 *
 * - 由 {@see \AdminService\Router\Router::load()} 引入, 可直接使用变量 `$router`
 * - 路径中的 `{name}` 与 `{name:约束}` 为参数占位符
 *
 * @var \AdminService\Router\Router $router
 */

// 示例:
// $router->get('/user/{id:\d+}', array(\app\demo\controller\Index::class,'index'))->name('user.show');
// $router->group(array('prefix'=>'/api/v1','middleware'=>array()),function(\AdminService\Router\Router $router): void {
//     $router->get('/profile',array(\app\demo\controller\Index::class,'request'));
// });
