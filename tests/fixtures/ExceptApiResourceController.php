<?php

namespace Tests\Fixtures;

use base\Attribute\ApiResource;

/**
 * 测试用 API 资源排除动作控制器
 *
 * - 从全量 API 标准动作中排除 destroy; 其余动作均已实现
 */
#[ApiResource('/partial-api', except: array('destroy'))]
class ExceptApiResourceController {

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

}
