<?php

namespace Tests\Fixtures;

/**
 * 测试用含非 public 标准动作的控制器
 */
class InaccessibleResourceController {

    /**
     * 列表动作
     * @return string
     */
    public function index(): string {
        return 'index';
    }

    /**
     * 非 public 的删除动作
     * @return string
     */
    protected function destroy(): string {
        return 'destroy';
    }

}
