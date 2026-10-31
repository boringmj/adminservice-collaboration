<?php

namespace Tests\Fixtures;

use base\Attribute\Resource;

/**
 * 测试用不可实例化资源控制器
 */
#[Resource('/abstract')]
abstract class AbstractResourceController {

    /**
     * 列表动作
     * @return string
     */
    public function index(): string {
        return 'index';
    }

}
