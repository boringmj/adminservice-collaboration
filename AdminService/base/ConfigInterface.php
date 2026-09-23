<?php

namespace base;

/**
 * 配置读取契约
 *
 * - 只读契约: 取值 / 判断存在 / 取全部; 负责加载与写入的是实现层
 * - 实现见 `AdminService\Config\Repository`(实例), 由应用层在引导期装配并登记进应用级容器;
 *   门面 `AdminService\Config` 也转发到同一个实例
 * - 契约层不得引用实现层
 *
 * @access public
 * @package base
 * @version 1.1.0
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
     * 判断配置项是否存在(键存在即为 true;值为 `null` 也算存在)
     *
     * @access public
     * @param string $key 配置键(如 `app.path`)
     * @return bool
     */
    public function has(string $key): bool;

    /**
     * 读取全部配置
     *
     * @access public
     * @return array<string,mixed>
     */
    public function all(): array;

}
