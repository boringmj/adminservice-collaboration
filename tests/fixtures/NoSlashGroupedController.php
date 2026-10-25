<?php

namespace Tests\Fixtures;

use base\Attribute\Route;
use base\Attribute\RouteGroup;

/**
 * 测试用无前导斜杠的属性路由控制器
 *
 * - 分组前缀带斜杠、方法路径不带斜杠, 用于固定路径拼接行为
 */
#[RouteGroup('/admin')]
class NoSlashGroupedController {

    /**
     * 方法子路径不带前导斜杠
     *
     * @access public
     * @return string
     */
    #[Route('GET','users',name:'admin.users')]
    public function users(): string {
        return 'admin users';
    }

}
