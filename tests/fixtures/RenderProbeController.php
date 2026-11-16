<?php

namespace Tests\Fixtures;

use AdminService\Config;

/**
 * 渲染作用域探针控制器
 *
 * - 在请求内写请求级配置, 供渲染阶段检查门面来源
 */
final class RenderProbeController {

    /**
     * 探测动作
     *
     * @access public
     * @return array<string,bool>
     */
    public function index(): array {
        Config::setValue('probe.render','request');
        return array('ok'=>true);
    }

}
