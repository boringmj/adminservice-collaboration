<?php

namespace Tests\Fixtures;

use base\Attribute\Route;
use base\Attribute\RouteGroup;
use base\Attribute\RouteParam;

/**
 * 测试用路由参数绑定控制器
 */
#[RouteGroup('/bind')]
class RouteBindingController {

    /**
     * 参数级绑定多个路由参数
     *
     * @access public
     * @param string $first_name 第一个名称
     * @param string $last_name 最后一个名称
     * @return string
     */
    #[Route('GET','/param/{first}/{last}')]
    public function parameter(
        #[RouteParam('first')] string $first_name,
        #[RouteParam('last')] string $last_name
    ): string {
        return 'param:'.$first_name.','.$last_name;
    }

    /**
     * 路由级绑定多个路由参数
     *
     * @access public
     * @param string $first_name 第一个名称
     * @param string $last_name 最后一个名称
     * @return string
     */
    #[Route('GET','/route/{first}/{last}',bind:array('first'=>'first_name','last'=>'last_name'))]
    public function route(string $first_name,string $last_name): string {
        return 'route:'.$first_name.','.$last_name;
    }

    /**
     * 参数级覆盖路由级绑定
     *
     * @access public
     * @param string $param_first 参数级绑定的第一个名称
     * @param string $param_last 参数级绑定的最后一个名称
     * @param string $route_first 被覆盖的路由级绑定默认值
     * @param string $route_last 被覆盖的路由级绑定默认值
     * @return string
     */
    #[Route('GET','/override/{first}/{last}',bind:array('first'=>'route_first','last'=>'route_last'))]
    public function override(
        #[RouteParam('first')] string $param_first,
        #[RouteParam('last')] string $param_last,
        string $route_first='default-first',
        string $route_last='default-last'
    ): string {
        return 'override:'.$param_first.','.$param_last.','.$route_first.','.$route_last;
    }

    /**
     * 参数级只覆盖部分路由级绑定
     *
     * @access public
     * @param string $param_first 参数级绑定的第一个名称
     * @param string $route_first 被覆盖的路由级绑定默认值
     * @param string $route_last 路由级绑定的最后一个名称
     * @return string
     */
    #[Route('GET','/mixed/{first}/{last}',bind:array('first'=>'route_first','last'=>'route_last'))]
    public function mixed(
        #[RouteParam('first')] string $param_first,
        string $route_first='default-first',
        string $route_last='default-last'
    ): string {
        return 'mixed:'.$param_first.','.$route_first.','.$route_last;
    }

    /**
     * 参数级绑定可选路由参数
     *
     * @access public
     * @param string $first_name 第一个名称
     * @param string $last_name 最后一个名称
     * @return string
     */
    #[Route('GET','/optional/{first?}/{last?}')]
    public function optional(
        #[RouteParam('first')] string $first_name='first-default',
        #[RouteParam('last')] string $last_name='last-default'
    ): string {
        return 'optional:'.$first_name.','.$last_name;
    }

}
