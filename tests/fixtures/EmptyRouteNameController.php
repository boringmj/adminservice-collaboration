<?php

namespace Tests\Fixtures;

use base\Attribute\Route;

/**
 * 测试用空路由名控制器
 */
class EmptyRouteNameController {

    /**
     * 显式空路由名
     *
     * @access public
     * @return void
     */
    #[Route('GET','/empty-name',name:'')]
    public function emptyName(): void {
    }

}
