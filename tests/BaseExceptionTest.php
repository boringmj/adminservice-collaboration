<?php

namespace Tests;

use PHPUnit\Framework\TestCase;

use Tests\Fixtures\ConfigNamedController;
use AdminService\HttpRequest;
use AdminService\Response as HttpResponse;
use AdminService\View;
use base\Exception\DependencyException;
use base\Route;

/**
 * 契约层异常用例
 *
 * - `base\Exception` 是**抽象**的(只作契约), 契约层不能实例化它、也不能引用实现层的 `AdminService\Exception`
 * - 因此"依赖未就绪"的分支抛的是契约层的具体异常 `base\Exception\DependencyException`
 * - 回归防线: 这三条分支以前写的是 `new Exception(...)`, 一旦执行会 fatal
 *   ("Cannot instantiate abstract class base\Exception") —— 平常用例走不到, 需要专门覆盖
 */
class BaseExceptionTest extends TestCase {

    /**
     * 测试手动构造控制器且未传依赖 → 抛契约层具体异常
     * @return void
     */
    public function testControllerWithoutDependencies(): void {
        $this->expectException(DependencyException::class);
        new ConfigNamedController();
    }

    /**
     * 测试调用 view() 但未注入配置契约 → 抛契约层具体异常
     * @return void
     */
    public function testViewWithoutConfigContract(): void {
        $controller=new ConfigNamedController(new HttpRequest(),new HttpResponse(),new View());
        $this->expectException(DependencyException::class);
        $controller->renderSomething();
    }

    /**
     * 测试手动构造路由且未传请求 → 抛契约层具体异常
     * @return void
     */
    public function testRouteWithoutRequest(): void {
        $this->expectException(DependencyException::class);
        new class extends Route {
            public function run(): void {}
        };
    }

}
