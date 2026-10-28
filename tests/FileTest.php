<?php

namespace Tests;

use PHPUnit\Framework\TestCase;
use ReflectionProperty;

use AdminService\Config\Repository;
use AdminService\Exception;
use AdminService\File;

use function file_get_contents;
use function file_put_contents;
use function glob;
use function in_array;
use function is_dir;
use function is_file;
use function mkdir;
use function rmdir;
use function stream_get_wrappers;
use function stream_wrapper_register;
use function stream_wrapper_unregister;
use function sys_get_temp_dir;
use function time;
use function uniqid;
use function unlink;
/**
 * File 存储测试
 *
 * - 写入前先完成编码；编码失败不能覆盖已有文件
 */
class FileTest extends TestCase {

    /**
     * 临时目录
     * @var string
     */
    private string $dir;

    /**
     * 文件配置
     * @var Repository
     */
    private Repository $config;

    /**
     * 每个测试前创建隔离目录
     * @return void
     */
    protected function setUp(): void {
        $this->dir=sys_get_temp_dir().'/adminservice_file_'.uniqid('',true);
        mkdir($this->dir,0755,true);
        $this->config=new Repository(array(
            'data'=>array(
                'path'=>$this->dir,
                'ext_name'=>'.data.json',
                'dir_mode'=>0755,
                'name_cycle'=>0
            )
        ));
    }

    /**
     * 每个测试后清理临时目录
     * @return void
     */
    protected function tearDown(): void {
        foreach(glob($this->dir.'/*')?:array() as $file)
            if(is_file($file))
                unlink($file);
        if(is_dir($this->dir))
            rmdir($this->dir);
    }

    /**
     * 正常写入后重新读取
     * @return void
     */
    public function testNormalWriteStillWorks(): void {
        $file=new File('normal',$this->config);
        $file->set('name','demo',true);
        $file->set('count',2,true);

        $reloaded=new File('normal',$this->config);
        $this->assertSame('demo',$reloaded->get('name'));
        $this->assertSame(2,$reloaded->get('count'));
    }

    /**
     * 编码失败必须抛异常
     * @return void
     */
    public function testWriteFailureThrowsException(): void {
        $file=new File('encode_failure',$this->config);
        $this->expectException(Exception::class);
        $file->set('bad',"\xB1\x31",true);
    }

    /**
     * 编码失败不能截断已有文件
     * @return void
     */
    public function testWriteFailureDoesNotTruncateExistingFile(): void {
        $file=new File('keep_previous',$this->config);
        $file->set('ok',array('a'=>1),true);
        $path=$this->dir.'/keep_previous.data.json';
        $before=file_get_contents($path);

        try {
            $file->set('bad',"\xB1\x31",true);
            $this->fail('Encoding failure should throw an exception.');
        } catch(Exception) {
        }

        $this->assertSame($before,file_get_contents($path));
    }

    /**
     * 显式 null 是有效值, 不能回落默认值
     * @return void
     */
    public function testGetKeepsExplicitNull(): void {
        $file=new File('explicit_null',$this->config);
        $file->set('nullable',null,true);

        $this->assertNull($file->get('nullable','fallback'));
        $this->assertSame('fallback',$file->get('missing','fallback'));
    }

    /**
     * 删除显式 null 值必须真正移除键并持久化
     * @return void
     */
    public function testDeleteRemovesExplicitNull(): void {
        $file=new File('delete_null',$this->config);
        $file->set('nullable',null,true);
        $file->delete('nullable',true);

        $this->assertSame('fallback',$file->get('nullable','fallback'));
        $this->assertSame(array(),$file->all(true));
        $reloaded=new File('delete_null',$this->config);
        $this->assertSame('fallback',$reloaded->get('nullable','fallback'));
        $this->assertSame(array(),$reloaded->all(true));
    }

    /**
     * 删除失败不能假装成功
     *
     * - 用测试流包装器固定 unlink 失败, 不依赖 Windows/Linux 的只读属性差异
     *
     * @return void
     */
    public function testDestroyFailureThrowsException(): void {
        $scheme='adminserviceunlinkfail';
        if(in_array($scheme,stream_get_wrappers(),true))
            stream_wrapper_unregister($scheme);
        $registered=stream_wrapper_register($scheme,UnlinkFailureStream::class);
        $this->assertTrue($registered,'无法注册删除失败测试流包装器');

        try {
            $file=new File('destroy_failure',$this->config);
            $file->set('x',1,true);
            $property=new ReflectionProperty(File::class,'file_path');
            $property->setAccessible(true);
            $property->setValue($file,$scheme.'://file');

            $this->expectException(Exception::class);
            $this->expectExceptionCode(100106);
            $file->destroy();
        } finally {
            if(in_array($scheme,stream_get_wrappers(),true))
                stream_wrapper_unregister($scheme);
        }
    }

    /**
     * 目录创建失败必须抛受控异常, 且错误码唯一
     * @return void
     */
    public function testDirectoryCreateFailureThrowsException(): void {
        $blocked=$this->dir.'/blocked';
        file_put_contents($blocked,'x');
        $config=new Repository(array(
            'data'=>array(
                'path'=>$blocked,
                'ext_name'=>'.data.json',
                'dir_mode'=>0755,
                'name_cycle'=>0
            )
        ));
        $this->expectException(Exception::class);
        $this->expectExceptionCode(100109);
        new File('nested',$config);
    }

    /**
     * 写入目标不可写时抛受控异常
     * @return void
     */
    public function testWriteTargetFailureThrowsException(): void {
        $target=$this->dir.'/write_target';
        mkdir($target);
        $config=new Repository(array(
            'data'=>array(
                'path'=>$this->dir,
                'ext_name'=>'',
                'dir_mode'=>0755,
                'name_cycle'=>0
            )
        ));
        try {
            $file=new File('write_target',$config);
            $this->expectException(Exception::class);
            $this->expectExceptionCode(100103);
            $file->set('x',1,true);
        } finally {
            if(is_dir($target))
                rmdir($target);
        }
    }

    /**
     * save() 未初始化使用独立错误码, 不与编码失败复用
     * @return void
     */
    public function testSaveUninitializedUsesOwnErrorCode(): void {
        $file=new File('save_uninitialized',$this->config);
        $file->set('x',1,true);
        $file->destroy();
        $this->expectException(Exception::class);
        $this->expectExceptionCode(100108);
        $file->save();
    }

}

/**
 * 只用于 FileTest 的流包装器: is_file() 返回 true, unlink() 固定失败
 */
final class UnlinkFailureStream {

    /**
     * PHP 流包装器上下文
     * @var mixed
     */
    public $context;

    /**
     * 提供文件 stat, 让 is_file() 认定目标存在
     *
     * @param string $path
     * @param int $flags
     * @return array|false
     */
    public function url_stat(string $path,int $flags): array|false {
        $now=time();
        return array(
            0=>0,
            1=>0,
            2=>0100644,
            3=>1,
            4=>0,
            5=>0,
            6=>0,
            7=>2,
            8=>$now,
            9=>$now,
            10=>$now,
            11=>-1,
            12=>-1,
            'dev'=>0,
            'ino'=>0,
            'mode'=>0100644,
            'nlink'=>1,
            'uid'=>0,
            'gid'=>0,
            'rdev'=>0,
            'size'=>2,
            'atime'=>$now,
            'mtime'=>$now,
            'ctime'=>$now,
            'blksize'=>-1,
            'blocks'=>-1
        );
    }

    /**
     * 固定返回删除失败
     *
     * @param string $path
     * @return bool
     */
    public function unlink(string $path): bool {
        return false;
    }

}
