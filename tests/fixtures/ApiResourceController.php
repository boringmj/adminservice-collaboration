<?php

namespace Tests\Fixtures;

use base\Attribute\ApiResource;

/**
 * 测试用 API 资源注解控制器
 */
#[ApiResource('/files')]
class ApiResourceController {

    /**
     * 列表动作
     * @return string
     */
    public function index(): string {
        return 'index';
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
