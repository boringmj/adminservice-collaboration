<?php

namespace Tests;

use PHPUnit\Framework\TestCase;

use AdminService\Config\Repository;
use AdminService\Log;
use AdminService\Runtime;

use ReflectionProperty;

use function is_file;
use function mkdir;
use function rmdir;
use function sys_get_temp_dir;
use function tempnam;
use function uniqid;
use function unlink;

/**
 * 日志目录与写入失败出口测试
 *
 * - 目录不可创建或写入失败时抛异常, 不再静默继续
 * - 配置缺失时目录与文件名键回落框架默认, 不再落向文件系统根
 */
class LogTest extends TestCase {

    /**
     * 测试: 目录创建失败时抛异常, 不再静默落到误导性报错
     * @return void
     */
    public function testDirectoryCreateFailureThrows(): void {
        // 路径被同名文件占住, mkdir 必然失败
        $blocker=tempnam(sys_get_temp_dir(),'log_blocker_');
        $config=new Repository(array('log'=>array(
            'path'=>$blocker,
            'rule'=>array('file'=>'/^[a-zA-Z0-9_\-]+$/')
        )));
        try {
            $this->expectException(\Exception::class);
            $this->expectExceptionMessage('日志目录创建失败');
            new Log('blocked',$config);
        } finally {
            unlink($blocker);
        }
    }

    /**
     * 测试: log.path 缺失时回落框架默认 runtime 目录
     * @return void
     */
    public function testMissingPathFallsBackToRuntimeLog(): void {
        $name='default_probe_'.uniqid();
        $log=new Log($name,new Repository(array()));
        $log_path=Runtime::path().'/log/'.$name.'.log';
        try {
            $property=new ReflectionProperty(Log::class,'log_path');
            $property->setAccessible(true);
            $this->assertSame($log_path,$property->getValue($log),'缺省配置应回落 runtime/log 而不是文件系统根');
        } finally {
            if(is_file($log_path))
                unlink($log_path);
        }
    }

    /**
     * 测试: 日志文件写入失败时抛异常, 不再静默丢日志
     * @return void
     */
    public function testWriteFailureThrows(): void {
        $dir=tempnam(sys_get_temp_dir(),'log_write_');
        unlink($dir);
        mkdir($dir);
        $config=new Repository(array('log'=>array(
            'path'=>$dir,
            'rule'=>array('file'=>'/^[a-zA-Z0-9_\-]+$/')
        )));
        $log=new Log('blocked',$config);
        $target=$dir.'/blocked.log';
        // 构造后把日志文件替换为目录: is_writable 仍为真, 但追加写入必然失败
        unlink($target);
        mkdir($target);
        try {
            $this->expectException(\Exception::class);
            $this->expectExceptionMessage('日志写入失败');
            $log->write('content');
        } finally {
            rmdir($target);
            rmdir($dir);
        }
    }

}
