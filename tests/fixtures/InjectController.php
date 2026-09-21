<?php

namespace Tests\Fixtures;

/**
 * 测试用控制器(形参注入)
 *
 * - 只有**路由参数**参与形参注入, 查询参数等输入须在方法内显式获取
 */
class InjectController {

    /**
     * 回显注入到形参的 name
     *
     * @access public
     * @param string $name 名称
     * @return string
     */
    public function greet(string $name='default'): string {
        return 'name='.$name;
    }

}
