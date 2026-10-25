<?php

namespace base;

/**
 * 控制器侧路由契约
 *
 * - 控制器只依赖本契约, 不感知具体路由实现(实现层见 `AdminService\Router\Router`)
 * - 当前只暴露控制器常用的 URL 反向生成与名称查询; 后续路由相关能力可在此扩展
 * - 当前请求的路由信息仍由 `base\RouteContextInterface` 提供, 避免把实现层对象带进契约
 *
 * @access public
 * @package base
 * @version 1.0.0
 */
interface RoutingInterface {

    /**
     * 按路由名生成 URL
     *
     * @access public
     * @param string $name 路由名
     * @param array<string,mixed> $params 路径参数
     * @return string
     * @throws \Exception 路由名不存在或缺少路径参数
     */
    public function url(string $name,array $params=array()): string;

    /**
     * 判断路由名是否已注册
     *
     * @access public
     * @param string $name 路由名
     * @return bool
     * @throws \Exception 路由名重复
     */
    public function has(string $name): bool;

}
