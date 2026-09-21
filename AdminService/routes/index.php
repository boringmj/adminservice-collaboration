<?php

use AdminService\Router\Router;
use app\index\controller\Index;

return function(Router $router): void {

    // 默认路由
    $router->any('/',array(Index::class,'index'));

};
