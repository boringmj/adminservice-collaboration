<?php

namespace base;

/**
 * 自动装配契约
 *
 * - 职责: 按 `#[AutowireProperty]` / `#[AutowireSetter]` / `#[AutowireMethod]` 三路装配对象
 * - 实现见 `AdminService\Autowire`; 容器 / 自定义构建流程按**契约**依赖它
 * - 契约层不得引用实现层
 *
 * @access public
 * @package base
 * @version 1.0.0
 */
interface AutowireInterface {

    /**
     * 自动装配对象(属性 → Setter → 生命周期方法)
     *
     * @access public
     * @param object $instance 待装配的对象实例
     * @param array<mixed> $flags 构建标识(引用传递, 用于阻断依赖注入死循环)
     * @return void
     * @throws \Exception
     */
    public function autowire(object $instance,array &$flags=array()): void;

}
