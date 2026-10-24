<?php

namespace Tests;

use PHPUnit\Framework\TestCase;

use AdminService\Config\Repository;
use AdminService\Exception;
use AdminService\File;

use function file_get_contents;
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

}
