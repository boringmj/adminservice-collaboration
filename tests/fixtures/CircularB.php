<?php

namespace Tests\Fixtures;

use AdminService\Autowire\AutowireProperty;

/**
 * 循环依赖用例 B(与 A 互相注入属性)
 *
 * @package Tests\Fixtures
 */
class CircularB {

    /**
     * 依赖 A
     * @var CircularA
     */
    #[AutowireProperty(CircularA::class)]
    public CircularA $a;

}
