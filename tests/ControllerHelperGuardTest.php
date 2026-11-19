<?php

namespace Tests;

use PHPUnit\Framework\TestCase;

use AdminService\App;
use AdminService\Container;
use AdminService\Exception;
use AdminService\HttpRequest;
use AdminService\Response;
use base\Request;
use base\Response as BaseResponse;

use function AdminService\common\json as controller_json;
use function AdminService\common\param;
use function AdminService\common\view;

/**
 * 公共助手函数的请求级守卫用例
 *
 * - `json()` / `param()` 只在请求处理内可用: 当前容器没有请求级实例(含手动 fork
 *   出的应用级子容器)时抛异常, 不再静默回落应用级容器自动装配
 * - 请求级实例已登记时行为不变, fork 自请求容器的子容器继续可用
 * - `view()` 守卫维持既有行为
 */
class ControllerHelperGuardTest extends TestCase {

    /**
     * 每个用例一个干净的应用级容器(无请求级实例)
     * @return void
     */
    protected function setUp(): void {
        App::setInstance(new Container());
    }

    /**
     * 测试无请求级响应实例时 helper json() 抛异常
     * @return void
     */
    public function testHelperJsonThrowsWithoutRequestInstance(): void {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('仅可在请求处理内调用 json()');
        controller_json(array('ok'=>true));
    }

    /**
     * 测试无请求级请求实例时 helper param() 抛异常
     * @return void
     */
    public function testHelperParamThrowsWithoutRequestInstance(): void {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('仅可在请求处理内调用 param()');
        param('id');
    }

    /**
     * 测试无路由上下文时 view() 守卫不回归
     * @return void
     */
    public function testHelperViewGuardUnchanged(): void {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('仅可在控制器内调用 view()');
        view('index');
    }

    /**
     * 测试请求级实例已登记时 json()/param() 行为不变
     * @return void
     */
    public function testHelperFunctionsWorkWithRequestInstances(): void {
        $request=new HttpRequest(array('query'=>array('id'=>'7')));
        $response=new Response();
        $container=App::getInstance();
        $container->instance(Request::class,$request);
        $container->instance(BaseResponse::class,$response);
        $this->assertSame('7',param('id'));
        $this->assertSame('7',param('id','x'));
        $this->assertSame('x',param('missing','x'));
        $this->assertSame(array('ok'=>true),controller_json(array('ok'=>true)));
        $this->assertSame(200,$response->status(),'helper json() 未显式传码不得覆盖默认状态码');
        $this->assertSame(array('ok'=>true),controller_json(array('ok'=>true),201));
        $this->assertSame(201,$response->status());
    }

    /**
     * 测试 fork 自请求容器的子容器内助手函数继续可用(父容器实例可见)
     * @return void
     */
    public function testHelperFunctionsVisibleInForkedRequestContainer(): void {
        $container=App::getInstance();
        $container->instance(Request::class,new HttpRequest(array('query'=>array('id'=>'7'))));
        $container->instance(BaseResponse::class,new Response());
        // 模拟协程内容器切换: 子容器成为当前容器
        App::setInstance($container->fork());
        $this->assertSame('7',param('id'));
        $this->assertSame(array('ok'=>true),controller_json(array('ok'=>true)));
    }

    /**
     * 测试手动 fork 自应用级容器的子容器(无请求级实例)内助手函数抛异常
     * @return void
     */
    public function testHelperFunctionsThrowInForkedApplicationContainer(): void {
        $previous=App::getInstance();
        App::setInstance($previous->fork());
        try {
            $this->expectException(Exception::class);
            $this->expectExceptionMessage('仅可在请求处理内调用 json()');
            controller_json(array('ok'=>true));
        } finally {
            App::setInstance($previous);
        }
    }

    /**
     * 测试 fork 自应用级容器的子容器内 param() 抛异常
     * @return void
     */
    public function testHelperParamThrowsInForkedApplicationContainer(): void {
        $previous=App::getInstance();
        App::setInstance($previous->fork());
        try {
            $this->expectException(Exception::class);
            $this->expectExceptionMessage('仅可在请求处理内调用 param()');
            param('id');
        } finally {
            App::setInstance($previous);
        }
    }

}
