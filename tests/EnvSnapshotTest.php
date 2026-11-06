<?php

namespace Tests;

use PHPUnit\Framework\TestCase;

use AdminService\Cache\EnvCache;
use AdminService\Config\Bootstrapper;
use AdminService\Runtime;

use function AdminService\env;
use function AdminService\env_reset;
use function AdminService\env_snapshot;
use function file_put_contents;
use function getenv;
use function is_file;
use function putenv;
use function str_replace;
use function sys_get_temp_dir;
use function tempnam;
use function uniqid;
use function unlink;

/**
 * 进程级环境快照与 Runtime 路径复位测试
 */
class EnvSnapshotTest extends TestCase {

    /**
     * 测试: env_reset 重新读取环境文件
     *
     * @return void
     */
    public function testEnvResetReloadsSnapshot(): void {
        $file=tempnam(sys_get_temp_dir(),'env_snapshot_');
        $previous=getenv('ADMINSERVICE_TEST_ENV_FILE');
        try {
            file_put_contents($file,"KEY=one\n");
            putenv('ADMINSERVICE_TEST_ENV_FILE='.$file);
            env_reset();
            $this->assertSame('one',env('KEY'));

            file_put_contents($file,"KEY=two\n");
            $this->assertSame('one',env('KEY'),'未复位前应继续使用旧快照');
            env_reset();
            $this->assertSame('two',env('KEY'));
        } finally {
            if($previous===false)
                putenv('ADMINSERVICE_TEST_ENV_FILE');
            else
                putenv('ADMINSERVICE_TEST_ENV_FILE='.$previous);
            env_reset();
            Runtime::reset();
            if(is_file($file))
                unlink($file);
        }
    }

    /**
     * 测试: Runtime::reload 重新读取 env 并重算路径
     *
     * @return void
     */
    public function testRuntimeReloadReadsNewEnvPath(): void {
        $file=tempnam(sys_get_temp_dir(),'env_runtime_');
        $previous=getenv('ADMINSERVICE_TEST_ENV_FILE');
        $path_a=str_replace('\\','/',sys_get_temp_dir().'/runtime_a');
        $path_b=str_replace('\\','/',sys_get_temp_dir().'/runtime_b');
        try {
            file_put_contents($file,"RUNTIME_PATH={$path_a}\n");
            putenv('ADMINSERVICE_TEST_ENV_FILE='.$file);
            env_reset();
            Runtime::reset();
            $this->assertSame($path_a,Runtime::path());

            file_put_contents($file,"RUNTIME_PATH={$path_b}\n");
            $this->assertSame($path_a,Runtime::path(),'未 reload 前应继续使用旧缓存');
            $this->assertSame($path_b,Runtime::reload());
        } finally {
            if($previous===false)
                putenv('ADMINSERVICE_TEST_ENV_FILE');
            else
                putenv('ADMINSERVICE_TEST_ENV_FILE='.$previous);
            env_reset();
            Runtime::reset();
            if(is_file($file))
                unlink($file);
        }
    }

    /**
     * 测试: 环境快照命中、锁定语义与损坏回落
     *
     * @return void
     */
    public function testEnvSnapshotIsLockedUntilRebuilt(): void {
        $env_file=tempnam(sys_get_temp_dir(),'env_snapshot_src_');
        $cache_file=sys_get_temp_dir().'/env_snapshot_cache_'.uniqid('',true).'.php';
        $previous_env=getenv('ADMINSERVICE_TEST_ENV_FILE');
        $previous_cache=getenv('ADMINSERVICE_TEST_ENV_CACHE');
        try {
            file_put_contents($env_file,"KEY=one\n");
            putenv('ADMINSERVICE_TEST_ENV_FILE='.$env_file);
            putenv('ADMINSERVICE_TEST_ENV_CACHE='.$cache_file);
            env_reset();
            $this->assertSame('one',env('KEY'));
            $this->assertFalse(env_snapshot()->compiled(),'未构建快照前应实时读取');

            $result=(new Bootstrapper())->buildEnvCache();
            $this->assertSame('built',$result['status']);
            $this->assertTrue($result['env']->compiled());
            $this->assertFileExists($cache_file);

            env_reset();
            $this->assertSame('one',env('KEY'));
            $this->assertTrue(env_snapshot()->compiled(),'构建后应使用快照');

            // 快照是权威值: 来源变化不会自动生效
            file_put_contents($env_file,"KEY=two-longer\n");
            env_reset();
            $this->assertSame('one',env('KEY'),'快照建立后应锁定来源文件');
            $status=EnvCache::status($env_file);
            $this->assertSame('stale',$status['status'],'状态检查应发现来源已变化');

            $result=(new Bootstrapper())->buildEnvCache();
            $this->assertSame('built',$result['status']);
            env_reset();
            $this->assertSame('two-longer',env('KEY'),'重新构建后应使用新值');

            file_put_contents($cache_file,'<?php return array("bad" => true);');
            env_reset();
            $this->assertSame('two-longer',env('KEY'),'损坏快照应回落实时读取');
        } finally {
            if($previous_env===false)
                putenv('ADMINSERVICE_TEST_ENV_FILE');
            else
                putenv('ADMINSERVICE_TEST_ENV_FILE='.$previous_env);
            if($previous_cache===false)
                putenv('ADMINSERVICE_TEST_ENV_CACHE');
            else
                putenv('ADMINSERVICE_TEST_ENV_CACHE='.$previous_cache);
            env_reset();
            Runtime::reset();
            if(is_file($cache_file))
                unlink($cache_file);
            if(is_file($env_file))
                unlink($env_file);
        }
    }

    /**
     * 测试: 自定义环境文件未指定快照路径时完全绕过快照
     *
     * @return void
     */
    public function testCustomEnvFileWithoutCachePathBypassesSnapshot(): void {
        $env_file=tempnam(sys_get_temp_dir(),'env_snapshot_live_');
        $previous_env=getenv('ADMINSERVICE_TEST_ENV_FILE');
        $previous_cache=getenv('ADMINSERVICE_TEST_ENV_CACHE');
        try {
            file_put_contents($env_file,"KEY=live\n");
            putenv('ADMINSERVICE_TEST_ENV_FILE='.$env_file);
            putenv('ADMINSERVICE_TEST_ENV_CACHE');
            env_reset();

            $result=(new Bootstrapper())->buildEnvCache();
            $this->assertSame('skipped',$result['status']);
            $this->assertFalse(env_snapshot()->compiled());
            $this->assertSame('live',env('KEY'));
        } finally {
            if($previous_env===false)
                putenv('ADMINSERVICE_TEST_ENV_FILE');
            else
                putenv('ADMINSERVICE_TEST_ENV_FILE='.$previous_env);
            if($previous_cache===false)
                putenv('ADMINSERVICE_TEST_ENV_CACHE');
            else
                putenv('ADMINSERVICE_TEST_ENV_CACHE='.$previous_cache);
            env_reset();
            Runtime::reset();
            if(is_file($env_file))
                unlink($env_file);
        }
    }

    /**
     * 测试: 没有 `.env` 时构建会清理旧快照
     *
     * @return void
     */
    public function testBuildEnvCacheClearsSnapshotWhenEnvFileMissing(): void {
        $env_file=tempnam(sys_get_temp_dir(),'env_snapshot_missing_');
        $cache_file=sys_get_temp_dir().'/env_snapshot_cache_'.uniqid('',true).'.php';
        $previous_env=getenv('ADMINSERVICE_TEST_ENV_FILE');
        $previous_cache=getenv('ADMINSERVICE_TEST_ENV_CACHE');
        try {
            file_put_contents($env_file,"KEY=one\n");
            putenv('ADMINSERVICE_TEST_ENV_FILE='.$env_file);
            putenv('ADMINSERVICE_TEST_ENV_CACHE='.$cache_file);
            env_reset();
            $this->assertSame('built',(new Bootstrapper())->buildEnvCache()['status']);
            $this->assertFileExists($cache_file);

            unlink($env_file);
            env_reset();
            $result=(new Bootstrapper())->buildEnvCache();
            $this->assertSame('skipped',$result['status']);
            $this->assertFileDoesNotExist($cache_file);
            env_reset();
            $this->assertFalse(env_snapshot()->compiled());
            $this->assertNull(env('KEY'));
        } finally {
            if($previous_env===false)
                putenv('ADMINSERVICE_TEST_ENV_FILE');
            else
                putenv('ADMINSERVICE_TEST_ENV_FILE='.$previous_env);
            if($previous_cache===false)
                putenv('ADMINSERVICE_TEST_ENV_CACHE');
            else
                putenv('ADMINSERVICE_TEST_ENV_CACHE='.$previous_cache);
            env_reset();
            Runtime::reset();
            if(is_file($cache_file))
                unlink($cache_file);
            if(is_file($env_file))
                unlink($env_file);
        }
    }

    /**
     * 测试: 进程级开关关闭环境快照
     *
     * @return void
     */
    public function testSwitchDisablesSnapshot(): void {
        $env_file=tempnam(sys_get_temp_dir(),'env_snapshot_switch_');
        $cache_file=sys_get_temp_dir().'/env_snapshot_cache_'.uniqid('',true).'.php';
        $previous_env=getenv('ADMINSERVICE_TEST_ENV_FILE');
        $previous_cache=getenv('ADMINSERVICE_TEST_ENV_CACHE');
        $previous_switch=getenv('ADMINSERVICE_ENV_SNAPSHOT');
        try {
            file_put_contents($env_file,"KEY=one\n");
            putenv('ADMINSERVICE_TEST_ENV_FILE='.$env_file);
            putenv('ADMINSERVICE_TEST_ENV_CACHE='.$cache_file);
            env_reset();
            $this->assertSame('built',(new Bootstrapper())->buildEnvCache()['status']);
            $this->assertFileExists($cache_file);

            putenv('ADMINSERVICE_ENV_SNAPSHOT=0');
            env_reset();
            $this->assertFalse(env_snapshot()->compiled(),'开关关闭时不读取快照');
            $this->assertSame('one',env('KEY'));
            $this->assertSame('skipped',(new Bootstrapper())->buildEnvCache()['status'],'开关关闭时不构建快照');
        } finally {
            if($previous_env===false)
                putenv('ADMINSERVICE_TEST_ENV_FILE');
            else
                putenv('ADMINSERVICE_TEST_ENV_FILE='.$previous_env);
            if($previous_cache===false)
                putenv('ADMINSERVICE_TEST_ENV_CACHE');
            else
                putenv('ADMINSERVICE_TEST_ENV_CACHE='.$previous_cache);
            if($previous_switch===false)
                putenv('ADMINSERVICE_ENV_SNAPSHOT');
            else
                putenv('ADMINSERVICE_ENV_SNAPSHOT='.$previous_switch);
            env_reset();
            Runtime::reset();
            if(is_file($cache_file))
                unlink($cache_file);
            if(is_file($env_file))
                unlink($env_file);
        }
    }

    /**
     * 测试: Runtime::reset 清除路径缓存
     *
     * @return void
     */
    public function testRuntimeResetClearsCachedPath(): void {
        $default=Runtime::path();
        Runtime::setPath('/tmp/adminservice-runtime-test');
        $this->assertSame('/tmp/adminservice-runtime-test',Runtime::path());

        Runtime::reset();
        $this->assertSame($default,Runtime::path());
    }

}
