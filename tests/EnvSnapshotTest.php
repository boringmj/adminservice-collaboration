<?php

namespace Tests;

use PHPUnit\Framework\TestCase;

use AdminService\Runtime;

use function AdminService\env;
use function AdminService\env_reset;
use function file_put_contents;
use function getenv;
use function is_file;
use function putenv;
use function sys_get_temp_dir;
use function tempnam;
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
