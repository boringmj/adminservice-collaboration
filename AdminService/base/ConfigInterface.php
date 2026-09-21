<?php

namespace base;

/**
 * 配置读取契约
 *
 * - 只读契约: 取值与取全部; 负责加载/写入的是实现层
 * - 实现见 `AdminService\ConfigProvider`(转发到框架的静态配置)
 * - 契约层不得引用实现层
 *
 * @access public
 * @package base
 * @version 1.0.0
 */
interface ConfigInterface {

    /**
     * 读取配置项(点分键, 支持默认值)
     *
     * @access public
     * @param string $key 配置键(如 `app.path`)
     * @param mixed $default 默认值
     * @return mixed
     */
    public function get(string $key,mixed $default=null): mixed;

    /**
     * 读取全部配置
     *
     * @access public
     * @return array<string,mixed>
     */
    public function all(): array;

}
