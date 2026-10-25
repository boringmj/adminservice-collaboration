<?php

namespace Tests\Fixtures;

use base\Controller;

/**
 * 路由契约控制器夹具
 *
 * - 暴露受保护的 `url()`，供控制器基类回归用例调用
 */
class RoutingController extends Controller {

    /**
     * 生成命名路由 URL
     *
     * @param string $name 路由参数
     * @return string
     */
    public function link(string $name): string {
        return $this->url('test.index',array('name'=>$name));
    }

}
