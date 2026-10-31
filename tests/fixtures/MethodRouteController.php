<?php

namespace Tests\Fixtures;

use base\Attribute\RouteMethod\Any;
use base\Attribute\RouteMethod\Delete;
use base\Attribute\RouteMethod\Get;
use base\Attribute\RouteMethod\Options;
use base\Attribute\RouteMethod\Patch;
use base\Attribute\RouteMethod\Post;
use base\Attribute\RouteMethod\Put;

/**
 * 方法型路由注解控制器
 */
class MethodRouteController {

    /**
     * GET 路由
     *
     * @access public
     * @return string
     */
    #[Get('/mg',name:'method.get')]
    public function get_only(): string {
        return 'get';
    }

    /**
     * POST 路由
     *
     * @access public
     * @return string
     */
    #[Post('/mp')]
    public function post_only(): string {
        return 'post';
    }

    /**
     * PUT 路由
     *
     * @access public
     * @return string
     */
    #[Put('/mu')]
    public function put_only(): string {
        return 'put';
    }

    /**
     * PATCH 路由
     *
     * @access public
     * @return string
     */
    #[Patch('/mpa')]
    public function patch_only(): string {
        return 'patch';
    }

    /**
     * DELETE 路由
     *
     * @access public
     * @return string
     */
    #[Delete('/md')]
    public function delete_only(): string {
        return 'delete';
    }

    /**
     * OPTIONS 路由
     *
     * @access public
     * @return string
     */
    #[Options('/mo')]
    public function options_only(): string {
        return 'options';
    }

    /**
     * 任意方法路由
     *
     * @access public
     * @return string
     */
    #[Any('/ma')]
    public function any_only(): string {
        return 'any';
    }

    /**
     * 同方法叠加 GET / POST(不同请求方法可共存)
     *
     * @access public
     * @return string
     */
    #[Get('/shared')]
    #[Post('/shared')]
    public function shared(): string {
        return 'shared';
    }

    /**
     * 带路由级绑定的 GET 路由
     *
     * @access public
     * @param string $user_id 绑定自 `{id}` 占位符
     * @return string
     */
    #[Get('/param/{id}',bind:array('id'=>'user_id'))]
    public function bound(string $user_id): string {
        return $user_id;
    }

}
