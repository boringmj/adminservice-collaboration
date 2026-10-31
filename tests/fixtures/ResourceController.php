<?php

namespace Tests\Fixtures;

use base\Attribute\Resource;
use base\Attribute\RouteGroup;

/**
 * 测试用资源注解控制器(类级分组 + 资源)
 */
#[RouteGroup('/index',middleware:array(FirstMiddleware::class))]
#[Resource('/users')]
class ResourceController {

    /**
     * 列表动作
     * @return string
     */
    public function index(): string {
        return 'index';
    }

    /**
     * 创建页动作
     * @return string
     */
    public function create(): string {
        return 'create';
    }

    /**
     * 保存动作
     * @return string
     */
    public function store(): string {
        return 'store';
    }

    /**
     * 详情动作
     * @return string
     */
    public function show(): string {
        return 'show';
    }

    /**
     * 编辑页动作
     * @return string
     */
    public function edit(): string {
        return 'edit';
    }

    /**
     * 更新动作
     * @return string
     */
    public function update(): string {
        return 'update';
    }

    /**
     * 删除动作
     * @return string
     */
    public function destroy(): string {
        return 'destroy';
    }

}
