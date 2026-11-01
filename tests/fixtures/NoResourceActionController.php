<?php

namespace Tests\Fixtures;

use base\Attribute\Resource;

/**
 * 测试用无标准资源动作控制器
 *
 * - 有资源声明但没有任何标准 public 动作
 */
#[Resource('/empty')]
class NoResourceActionController {

    /**
     * 非标准动作
     * @return string
     */
    public function ping(): string {
        return 'pong';
    }

}
