<?php

namespace Tests;

use PHPUnit\Framework\TestCase;

use AdminService\Config;
use AdminService\Config\Env;
use AdminService\Runtime;

use function dirname;
use function getenv;
use function putenv;
use function str_replace;
use function sys_get_temp_dir;
use function uniqid;

/**
 * Runtime 路径解析测试
 *
 * - runtime 根必须在配置加载前可用
 * - 配置默认路径必须派生自同一个 runtime 根
 * - 允许测试/自定义入口通过 setPath() 覆盖, 结束后恢复
 * - 测试入口环境变量优先于 `.env` 的 RUNTIME_PATH
 */
class RuntimeTest extends TestCase {

    /**
     * 每个用例后恢复默认 runtime 与配置
     *
     * @return void
     */
    protected function tearDown(): void {
        Runtime::reset();
        load_framework_config();
    }

    /**
     * 测试: 默认 runtime 位于 AdminService/runtime
     *
     * - 使用空 Env 隔离真实 `.env`, 避免本机配置 `RUNTIME_PATH` 时误判
     *
     * @return void
     */
    public function testDefaultRuntimePath(): void {
        $expected=str_replace('\\','/',dirname(__DIR__).'/AdminService/runtime');
        $this->assertSame($expected,Runtime::path(new Env()));
    }

    /**
     * 测试: 文件系统根不能作为 runtime 根, 回落默认目录
     *
     * @return void
     */
    public function testFilesystemRootFallsBackToDefault(): void {
        $expected=str_replace('\\','/',dirname(__DIR__).'/AdminService/runtime');
        foreach(array('/','//','C:/','C:\\') as $value) {
            $env=new Env('RUNTIME_PATH='.$value);
            $this->assertSame($expected,Runtime::path($env),'RUNTIME_PATH='.$value);
        }
    }

    /**
     * 测试: RUNTIME_PATH 覆盖默认值并规范化
     *
     * @return void
     */
    public function testRuntimePathUsesEnvValue(): void {
        $env=new Env("RUNTIME_PATH=/tmp/runtime/");
        $this->assertSame('/tmp/runtime',Runtime::path($env));
    }

    /**
     * 测试: 测试入口环境变量优先于 `.env` 的 RUNTIME_PATH
     *
     * @return void
     */
    public function testTestRuntimeOverrideTakesPrecedence(): void {
        $name='ADMINSERVICE_TEST_RUNTIME';
        $old=getenv($name);
        $path=sys_get_temp_dir().'/runtime_override_'.uniqid('',true);
        putenv($name.'='.$path.'/');
        try {
            $env=new Env('RUNTIME_PATH=/tmp/from-env');
            $this->assertSame(str_replace('\\','/',$path),Runtime::path($env));
        } finally {
            if($old===false)
                putenv($name);
            else
                putenv($name.'='.$old);
            Runtime::reset();
        }
    }

    /**
     * 测试: RUNTIME_PATH 为空时回落默认值
     *
     * @return void
     */
    public function testEmptyRuntimePathFallsBackToDefault(): void {
        $env=new Env("RUNTIME_PATH=");
        $expected=str_replace('\\','/',dirname(__DIR__).'/AdminService/runtime');
        $this->assertSame($expected,Runtime::path($env));
    }

    /**
     * 测试: setPath() 规范化分隔符与末尾斜杠, 并缓存结果
     *
     * @return void
     */
    public function testSetPathNormalizesAndCaches(): void {
        $path=sys_get_temp_dir().'/runtime_'.uniqid('',true);
        Runtime::setPath($path.'/nested\\');
        $expected=str_replace('\\','/',$path).'/nested';
        $this->assertSame($expected,Runtime::path());
        $this->assertSame($expected,Runtime::path(),'重复调用应命中进程内缓存');
    }

    /**
     * 测试: 默认配置的数据/缓存/日志/上传路径都落在 runtime 下
     *
     * @return void
     */
    public function testConfigDefaultsFollowRuntimePath(): void {
        $path=sys_get_temp_dir().'/runtime_cfg_'.uniqid('',true);
        Runtime::setPath($path);
        load_framework_config();

        $this->assertSame(Runtime::path().'/data',Config::get('data.path'));
        $this->assertSame(Runtime::path().'/cache',Config::get('cache.path'));
        $this->assertSame(Runtime::path().'/log',Config::get('log.path'));
        $this->assertSame(Runtime::path().'/uploads',Config::get('request.default.upload.save.dir'));
        $this->assertSame(0755,Config::get('data.dir_mode'));
        $this->assertSame(0755,Config::get('log.dir_mode'));
        $this->assertSame(0755,Config::get('cache.dir_mode'));
    }

    /**
     * 测试: 通用缓存与编译缓存配置键存在
     *
     * @return void
     */
    public function testCacheConfigDefaultsArePresent(): void {
        $this->assertSame('default',Config::get('cache.default_pool'));
        $this->assertSame(3600,Config::get('cache.default_ttl'));
        $this->assertSame(1,Config::get('cache.gc_probability'));
        $this->assertSame(100,Config::get('cache.gc_divisor'));
        $this->assertSame(200,Config::get('cache.gc_max_files'));

        $compile=Config::get('cache.compile');
        $this->assertIsArray($compile);
        $this->assertArrayHasKey('enabled',$compile);
        $this->assertArrayHasKey('mode',$compile);
        $this->assertArrayHasKey('config',$compile);
        $this->assertArrayHasKey('route',$compile);
        $this->assertArrayHasKey('container',$compile);
    }

}
