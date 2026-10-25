<?php

namespace Tests;

use PHPUnit\Framework\TestCase;

use AdminService\Container;
use AdminService\Error;
use ReflectionMethod;
use ReflectionProperty;

use function fclose;
use function file_get_contents;
use function is_file;
use function is_resource;
use function proc_close;
use function proc_open;
use function restore_error_handler;
use function set_error_handler;
use function stream_get_contents;
use function strpos;
use function sys_get_temp_dir;
use function tempnam;
use function trigger_error;
use function unlink;

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
     * 每个用例前复位请求级容器, 避免其它用例的 Application::handle() 残留影响
     * @return void
     */
    protected function setUp(): void {
        Error::setRequestContainer(null);
    }

    /**
     * 每个用例后复位错误收集与容器引用, 避免影响其它用例
     * @return void
     */
    protected function tearDown(): void {
        self::setErrors(array());
        Error::setContainer(null);
        Error::setRequestContainer(null);
    }

    /**
     * @ 抑制的警告不应进入错误收集
     * @return void
     */
    public function testAtSuppressedWarningIsNotCollected(): void {
        self::setErrors(array());
        set_error_handler(array(Error::class,'handleError'),E_ALL);
        try {
            @trigger_error('suppressed warning',E_USER_WARNING);
        } finally {
            restore_error_handler();
        }
        $this->assertSame(array(),Error::getErrors());
    }

    /**
     * 普通警告仍应被错误处理器收集
     * @return void
     */
    public function testNormalWarningIsCollected(): void {
        self::setErrors(array());
        set_error_handler(array(Error::class,'handleError'),E_ALL);
        try {
            trigger_error('normal warning',E_USER_WARNING);
        } finally {
            restore_error_handler();
        }
        $errors=Error::getErrors();
        $this->assertCount(1,$errors);
        $this->assertSame('normal warning',$errors[0]['message']);
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
     * 测试正常结束时后续 shutdown 回调仍会执行
     * @return void
     */
    public function testShutdownCallbacksContinueAfterNormalExit(): void {
        $marker=tempnam(sys_get_temp_dir(),'shutdown_normal_');
        try {
            list($status,,$stderr)=$this->runFixture(
                __DIR__.'/fixtures/shutdown_callbacks.php',
                array($marker,'normal')
            );
            $this->assertSame(0,$status,$stderr);
            $this->assertSame('ok',(string)file_get_contents($marker));
        } finally {
            if(is_file($marker))
                unlink($marker);
        }
    }

    /**
     * 测试错误出口渲染后后续 shutdown 回调仍会执行
     * @return void
     */
    public function testShutdownCallbacksContinueAfterCollectedError(): void {
        $marker=tempnam(sys_get_temp_dir(),'shutdown_warning_');
        try {
            list($status,,$stderr)=$this->runFixture(
                __DIR__.'/fixtures/shutdown_callbacks.php',
                array($marker,'warning')
            );
            $this->assertSame(0,$status,$stderr);
            $this->assertSame('ok',(string)file_get_contents($marker));
        } finally {
            if(is_file($marker))
                unlink($marker);
        }
    }

    /**
     * 测试错误日志先于错误页输出
     *
     * - 客户端断连导致输出失败时, 不能把日志写入留到之后
     *
     * @return void
     */
    public function testErrorLogIsWrittenBeforeOutput(): void {
        list($status,$stdout,$stderr)=$this->runFixture(
            __DIR__.'/fixtures/error_log_order.php'
        );
        $this->assertSame(0,$status,$stderr);
        $logPos=strpos($stdout,'|LOG|');
        $errorPos=strpos($stdout,'|PAGE|');
        $this->assertNotFalse($logPos,'未调用日志写入');
        $this->assertNotFalse($errorPos,'错误页未输出');
        $this->assertLessThan($errorPos,$logPos,'日志必须先于错误页输出');
    }

    /**
     * 运行夹具脚本
     *
     * @param string $script 脚本路径
     * @param array<int,string> $args 脚本参数
     * @return array{0:int,1:string,2:string} 状态码 / stdout / stderr
     */
    private function runFixture(string $script,array $args=array()): array {
        $command=array(PHP_BINARY,'-d','display_errors=0',$script);
        foreach($args as $arg)
            $command[]=$arg;
        $process=proc_open($command,array(
            1=>array('pipe','w'),
            2=>array('pipe','w')
        ),$pipes);
        $this->assertTrue(is_resource($process),'无法启动子进程');
        $stdout=(string)stream_get_contents($pipes[1]);
        $stderr=(string)stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status=proc_close($process);
        return array($status,$stdout,$stderr);
    }

    /**
     * 测试注册前 error_reporting 为 0 时普通警告仍会被收集
     * @return void
     */
    public function testRegisterCollectsWarningsWhenReportingWasZero(): void {
        list($status,$stdout,$stderr)=$this->runFixture(
            __DIR__.'/fixtures/register_warning.php'
        );
        $this->assertSame(0,$status,$stderr);
        $this->assertStringContainsString('registered warning',$stdout);
    }

    /**
     * 测试错误出口使用请求级响应
     * @return void
     */
    public function testErrorOutletUsesRequestResponse(): void {
        $marker=tempnam(sys_get_temp_dir(),'error_response_scope_');
        try {
            list($status,,$stderr)=$this->runFixture(
                __DIR__.'/fixtures/error_response_scope.php',
                array($marker)
            );
            $this->assertSame(0,$status,$stderr);
            $this->assertSame("request\n",(string)file_get_contents($marker));
        } finally {
            if(is_file($marker))
                unlink($marker);
        }
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
     * 测试引导期未完成时的进程级错误出口
     *
     * - 独立进程模拟 `Main::init()` 早期抛异常(错误处理器已注册, 容器/配置尚未完成)
     * - 必须输出内置最小错误页, 并写出 `[AdminService]` 兜底日志
     * @return void
     */
    public function testBootFailureUsesMinimalPageAndFallbackLog(): void {
        $script=__DIR__.'/fixtures/boot_error.php';
        $log_file=tempnam(sys_get_temp_dir(),'boot_error_log_');
        try {
            $process=proc_open(array(PHP_BINARY,'-d','display_errors=0',$script,$log_file),array(
                1=>array('pipe','w'),
                2=>array('pipe','w')
            ),$pipes);
            $this->assertTrue(is_resource($process),'无法启动子进程');
            $stdout=(string)stream_get_contents($pipes[1]);
            $stderr=(string)stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $status=proc_close($process);
            $log=(string)file_get_contents($log_file);
            $this->assertSame(0,$status,'错误出口应以正常退出码结束: '.$stderr);
            $this->assertStringContainsString('发生错误',$stdout);
            $this->assertStringContainsString('引导前错误',$stdout);
            $this->assertStringContainsString('[AdminService]',$log);
            $this->assertStringContainsString('引导前错误',$log);
        } finally {
            if(is_file($log_file))
                unlink($log_file);
        }
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
