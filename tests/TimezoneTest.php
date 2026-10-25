<?php

namespace Tests;

use PHPUnit\Framework\TestCase;

use AdminService\Config;

/**
 * 时区配置用例
 *
 * - 框架默认时区由 `app.timezone` 统一控制
 * - 测试引导与 `Main::init()` 都必须应用同一配置, 避免测试日志与 HTTP 日志时区不一致
 */
class TimezoneTest extends TestCase {

    /**
     * 当前时区(用于恢复)
     * @var string
     */
    private string $previous;

    /**
     * 每个用例前保存原时区
     * @return void
     */
    protected function setUp(): void {
        $this->previous=date_default_timezone_get();
    }

    /**
     * 每个用例后恢复原时区
     * @return void
     */
    protected function tearDown(): void {
        date_default_timezone_set($this->previous);
    }

    /**
     * 测试配置中声明了默认时区
     * @return void
     */
    public function testAppConfigDeclaresTimezone(): void {
        load_framework_config();
        $this->assertSame('Asia/Shanghai',Config::get('app.timezone'));
    }

    /**
     * 测试测试引导应用配置时区
     * @return void
     */
    public function testTestBootstrapAppliesConfiguredTimezone(): void {
        date_default_timezone_set('UTC');
        load_test_config();
        $this->assertSame(Config::get('app.timezone'),date_default_timezone_get());
    }

}
