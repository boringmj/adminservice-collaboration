<?php

namespace Tests;

use PHPUnit\Framework\TestCase;

use AdminService\Container;
use AdminService\Error;
use ReflectionMethod;
use ReflectionProperty;

/**
 * 错误处理用例
 *
 * - 覆盖"容器注入"这条新链路: 错误路径不再经静态门面
 * - 重点覆盖**容器不可用时的兜底**: 仍能渲染出内置错误页(不依赖容器与配置)
 */
class ErrorTest extends TestCase {

    /**
     * 类初始化前执行
     * @return void
     */
    public static function setUpBeforeClass(): void {
        load_test_config();
    }

    /**
     * 每个用例后复位错误收集与容器引用, 避免影响其它用例
     * @return void
     */
    protected function tearDown(): void {
        self::setErrors(array());
        Error::setContainer(null);
    }

    /**
     * 测试容器注入接口
     * @return void
     */
    public function testContainerSeam(): void {
        $container=new Container();
        Error::setContainer($container);
        $this->assertSame($container,Error::container());
        Error::setContainer(null);
        $this->assertNull(Error::container());
    }

    /**
     * 测试容器不可用时的兜底错误页(不依赖容器与配置)
     * @return void
     */
    public function testRenderErrorsWithoutContainer(): void {
        Error::setContainer(null);
        self::setErrors(array(self::error('测试错误信息')));
        $html=self::renderErrors();
        $this->assertStringContainsString('发生错误',$html);
        $this->assertStringContainsString('测试错误信息',$html);
    }

    /**
     * 测试容器可用时经视图服务渲染错误页(配置里的 error_template)
     * @return void
     */
    public function testRenderErrorsWithContainer(): void {
        Error::setContainer(new Container());
        self::setErrors(array(self::error('经模板渲染的错误')));
        $html=self::renderErrors();
        $this->assertStringContainsString('经模板渲染的错误',$html);
    }

    /**
     * 造一条错误记录
     *
     * @param string $message 错误消息
     * @return array<string,mixed>
     */
    private static function error(string $message): array {
        // 与 handleError() / handleException() 写入的记录同形
        return array(
            'type'=>E_WARNING,
            'message'=>$message,
            'file'=>__FILE__,
            'line'=>__LINE__,
            'is_fatal'=>false,
            'stack_trace'=>'',
            'is_exception'=>false
        );
    }

    /**
     * 写入错误收集区(受保护静态属性)
     *
     * @param array<mixed> $errors 错误列表
     * @return void
     */
    private static function setErrors(array $errors): void {
        $property=new ReflectionProperty(\base\Error::class,'errors');
        $property->setAccessible(true);
        $property->setValue(null,$errors);
    }

    /**
     * 调用错误页渲染(私有静态方法)
     *
     * @return string
     */
    private static function renderErrors(): string {
        $method=new ReflectionMethod(Error::class,'renderErrors');
        $method->setAccessible(true);
        return (string)$method->invoke(null);
    }

}
