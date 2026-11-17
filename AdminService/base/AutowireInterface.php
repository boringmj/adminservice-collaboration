<?php

namespace base;

/**
 * 自动装配契约
 *
 * - 职责: 按 `#[AutowireProperty]` / `#[AutowireSetter]` / `#[AutowireLifecycle]` / `#[AutowireMethod]` 装配对象
 * - 注入模式由目标注解的 `AutowireMode` 参数指定; 延迟任务需由调用方在根对象登记后刷新
 * - 契约供自定义构建流程使用; 默认装配由框架容器完成
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
