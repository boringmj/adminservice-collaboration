<?php

namespace Tests\Fixtures;

use AdminService\Autowire\AutowireProperty;

/**
 * 循环依赖用例 A(与 B 互相注入属性)
 *
 * - 用于验证装配器把**构建标识(引用传递的 $flags)**透传给容器: 丢了它, 这里会无限递归
 *
 * @package Tests\Fixtures
 */
class CircularA {

    /**
     * 依赖 B
     * @var CircularB
     */
    #[AutowireProperty(CircularB::class)]
    public CircularB $b;

}
