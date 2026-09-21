<?php

namespace AdminService;

use base\ConfigInterface;

/**
 * 配置读取契约的实例化适配
 *
 * - 框架的配置以静态类 `AdminService\Config` 承载, 而契约层要的是**可注入的实例**
 * - 本类只做转发(不持有状态), 让契约层组件(控制器基类等)可以按 `base\ConfigInterface` 依赖
 *
 * @access public
 * @package AdminService
 * @version 1.0.0
 */
final class ConfigProvider implements ConfigInterface {

    /**
     * 读取配置项
     *
     * @access public
     * @param string $key 配置键(如 `app.path`)
     * @param mixed $default 默认值
     * @return mixed
     */
    public function get(string $key,mixed $default=null): mixed {
        return Config::get($key,$default);
    }

    /**
     * 读取全部配置
     *
     * @access public
     * @return array<string,mixed>
     */
    public function all(): array {
        return Config::all();
    }

}
