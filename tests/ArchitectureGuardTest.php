<?php

namespace Tests;

use PHPUnit\Framework\TestCase;

use function dirname;
use function file_get_contents;
use function in_array;
use function is_array;
use function preg_match;
use function token_get_all;

/**
 * 架构守卫
 *
 * - 检查请求出口不重新依赖 App 定位服务
 * - 检查请求级对象不新增可变静态属性
 * - 检查 Router 命中态按执行流隔离
 * - 检查请求对象不写入应用级容器
 */
final class ArchitectureGuardTest extends TestCase {

    /**
     * 核心目录
     * @var string
     */
    private string $core;

    /**
     * 初始化核心目录
     *
     * @access protected
     * @return void
     */
    protected function setUp(): void {
        $this->core=dirname(__DIR__).'/AdminService';
    }

    /**
     * 读取源码并移除注释
     *
     * @access private
     * @param string $relative 相对核心目录的路径
     * @return string
     */
    private function code(string $relative): string {
        $source=(string)file_get_contents($this->core.'/'.$relative);
        $code='';
        foreach(token_get_all($source) as $token) {
            if(is_array($token)) {
                if(in_array($token[0],array(T_COMMENT,T_DOC_COMMENT),true))
                    continue;
                $code.=$token[1];
                continue;
            }
            $code.=$token;
        }
        return $code;
    }

    /**
     * 检查响应与生命周期出口不使用 App 定位服务
     *
     * @access public
     * @return void
     */
    public function testResponseLifecycleDoesNotUseAppLocator(): void {
        $pattern='/\bApp::(?:get|new|build)\s*\(/';
        foreach(array('core/Response.php','core/Error.php','Main.php') as $file) {
            $this->assertSame(
                0,
                preg_match($pattern,$this->code($file)),
                $file.' 不应通过 App 定位请求级服务'
            );
        }
    }

    /**
     * 检查请求级对象没有可变静态属性
     *
     * @access public
     * @return void
     */
    public function testRequestScopedClassesHaveNoMutableStaticProperties(): void {
        $pattern='/\b(?:private|protected|public)\s+static\s+(?!function\b)[^;{]*\$/';
        $files=array(
            'core/Response.php',
            'core/HttpRequest.php',
            'core/Cookie.php',
            'core/RouteContext.php',
            'core/UploadFiles.php',
            'core/UploadFilesForm.php',
            'core/UploadStorage.php',
            'core/ArraySession.php',
            'core/NativeSession.php'
        );
        foreach($files as $file) {
            $this->assertSame(
                0,
                preg_match($pattern,$this->code($file)),
                $file.' 不应声明可变静态属性'
            );
        }
    }

    /**
     * 检查 Router 命中态不回到共享标量属性
     *
     * @access public
     * @return void
     */
    public function testRouterCurrentIsExecutionScoped(): void {
        $code=$this->code('core/Router/Router.php');
        $this->assertSame(
            0,
            preg_match('/private\s+\?RouteItem\s+\$current\s*=/',$code),
            'Router 不应使用共享 current 属性保存命中路由'
        );
        $this->assertStringContainsString('current_by_context',$code);
        $this->assertStringContainsString('ContextStorage::current()',$code);
    }

    /**
     * 检查请求对象不写入应用级容器
     *
     * @access public
     * @return void
     */
    public function testApplicationDoesNotRegisterRequestObjectsOnApplicationContainer(): void {
        $pattern='/\$this->container->instance\s*\(\s*(?:Request|Response|AbstractSession|Cookie|RouteContext)::class/';
        $this->assertSame(
            0,
            preg_match($pattern,$this->code('core/Application.php')),
            '请求级对象不应写入应用级容器'
        );
    }

    /**
     * 检查 Config 门面优先读取请求上下文
     *
     * @access public
     * @return void
     */
    public function testConfigFacadeReadsRequestContext(): void {
        $code=$this->code('core/Config.php');
        $this->assertStringContainsString('ContextStorage::current()',$code);
        $this->assertStringContainsString('$context->config()',$code);
    }

    /**
     * 检查请求配置使用副本
     *
     * @access public
     * @return void
     */
    public function testApplicationForksRequestConfig(): void {
        $this->assertStringContainsString(
            '$repository->fork()',
            $this->code('core/Application.php')
        );
    }

}
