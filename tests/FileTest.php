<?php

namespace Tests;

use PHPUnit\Framework\TestCase;

use AdminService\Config\Repository;
use AdminService\Exception;
use AdminService\File;

use function chmod;
use function file_get_contents;
use function file_put_contents;
use function glob;
use function is_dir;
use function is_file;
use function mkdir;
use function rmdir;
use function sys_get_temp_dir;
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
     * - 只在当前平台确实能把只读文件设为不可删除时执行
     *
     * @return void
     */
    public function testDestroyFailureThrowsException(): void {
        $file=new File('destroy_failure',$this->config);
        $file->set('x',1,true);
        $path=$this->dir.'/destroy_failure.data.json';
        @chmod($path,0444);

        $probe=$this->dir.'/destroy_probe';
        file_put_contents($probe,'x');
        @chmod($probe,0444);
        $unlinkFails=!@unlink($probe);
        @chmod($probe,0644);
        if(is_file($probe))
            @unlink($probe);
        if(!$unlinkFails) {
            @chmod($path,0644);
            $this->markTestSkipped('当前平台无法通过只读属性模拟删除失败');
        }

        try {
            $this->expectException(Exception::class);
            $this->expectExceptionCode(100106);
            $file->destroy();
        } finally {
            @chmod($path,0644);
            if(is_file($path))
                @unlink($path);
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
