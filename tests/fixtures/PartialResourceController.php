<?php

namespace Tests\Fixtures;

use base\Attribute\Resource;

/**
 * 测试用资源动作白名单控制器
 *
 * - 类级注解只注册 index/show
 */
#[Resource('/partial', only: array('index', 'show'))]
class PartialResourceController {

    /**
     * 列表动作
     * @return string
     */
    public function index(): string {
        return 'index';
    }

    /**
     * 详情动作
     * @return string
     */
    public function show(): string {
        return 'show';
    }

}
