<?php

namespace Tests\Fixtures;

use AdminService\Attribute\Route;
use AdminService\Attribute\RouteGroup;

/**
 * 测试用属性路由控制器(控制器级分组 + 命名 + 多路径)
 */
#[RouteGroup('/g',middleware:array(FirstMiddleware::class))]
class GroupedController {

    /**
     * 可选参数 + 命名
     *
     * @access public
     * @param string $name 名称
     * @return string
     */
    #[Route('GET','/{name?}',name:'g.hello')]
    public function hello(string $name='World'): string {
        return 'Hello '.$name;
    }

    /**
     * 同一处理器的多条路径
     *
     * @access public
     * @return string
     */
    #[Route('GET','/a')]
    #[Route('GET','/b')]
    public function alias(): string {
        return 'alias';
    }

}
