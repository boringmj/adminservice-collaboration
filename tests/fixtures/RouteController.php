<?php

namespace Tests\Fixtures;

use base\Attribute\Route;

/**
 * 测试用属性路由控制器
 */
class RouteController {

    /**
     * 属性声明路由
     *
     * @access public
     * @param string $name 名称
     * @return string
     */
    #[Route('GET','/hello/{name}')]
    public function hello(string $name): string {
        return 'hello '.$name;
    }

    /**
     * 属性声明不限方法路由
     *
     * @access public
     * @return string
     */
    #[Route('*','/ping')]
    public function ping(): string {
        return 'pong';
    }

    /**
     * 未声明路由的方法
     *
     * @access public
     * @return string
     */
    public function ignored(): string {
        return 'ignored';
    }

    /**
     * 接收通配参数(通配糖 `*` 的参数名固定为 any)
     *
     * @access public
     * @param string $any 通配捕获值
     * @return string
     */
    public function wildcard(string $any=''): string {
        return 'any='.$any;
    }

}
