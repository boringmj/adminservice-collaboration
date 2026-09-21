<?php

namespace base;

/**
 * 路由上下文契约
 *
 * - 描述"当前正在分发的那条路由": 应用名 / 控制器名 / 方法名 / 处理器 / 路径参数
 * - 实现由实现层提供, 属**请求级**对象(每请求一个, 请求结束随请求丢弃)
 * - 控制器基类、视图路径推断与 `App::getAppName()` 系列都经它取值, 从而不再依赖全局数据袋
 * - 契约层不得引用实现层
 *
 * @access public
 * @package base
 * @version 1.0.0
 */
interface RouteContextInterface {

    /**
     * 应用名(如 `index` / `demo`)
     *
     * @access public
     * @return string|null
     */
    public function appName(): ?string;

    /**
     * 控制器名(不含命名空间)
     *
     * @access public
     * @return string|null
     */
    public function controllerName(): ?string;

    /**
     * 方法名
     *
     * @access public
     * @return string|null
     */
    public function methodName(): ?string;

    /**
     * 控制器类名(完整命名空间)
     *
     * - 控制器实例由容器按该类名登记, 因此助手函数与视图路径推断可以据此取用
     *
     * @access public
     * @return string|null
     */
    public function controllerClass(): ?string;

    /**
     * 路径参数(路由参数)
     *
     * @access public
     * @return array<string,mixed>
     */
    public function params(): array;

}
