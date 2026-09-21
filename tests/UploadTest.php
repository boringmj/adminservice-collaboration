<?php

namespace Tests;

use PHPUnit\Framework\TestCase;

use AdminService\App;
use AdminService\Config;
use AdminService\UploadFile;
use AdminService\UploadFiles;
use AdminService\UploadStorage;
use AdminService\exception\UploadException;
use AdminService\exception\UploadStorageException;
use Tests\Fixtures\FakeUploadStorage;

/**
 * 上传测试
 *
 * - 覆盖哈希在保存前算好、保存目录由存储方准备、解析阶段不触碰目录
 * - `$_FILES` 解析依赖真实上传(`is_uploaded_file`), 由真实请求冒烟覆盖
 */
class UploadTest extends TestCase {

    /**
     * 临时文件路径
     * @var array<string>
     */
    private array $tempFiles=array();

    /**
     * 类初始化前执行
     * @return void
     */
    public static function setUpBeforeClass(): void {
        App::init();
    }

    /**
     * 每个测试后清理临时文件
     * @return void
     */
    protected function tearDown(): void {
        foreach($this->tempFiles as $file)
            if(is_file($file))
                unlink($file);
        $this->tempFiles=array();
        Config::load();
    }

    /**
     * 构造一个上传文件实例
     *
     * @access private
     * @param string $content 文件内容
     * @param string|null $confirm_dir 保存目录(null时使用临时目录)
     * @return UploadFile
     */
    private function makeFile(string $content,?string $confirm_dir=null): UploadFile {
        $path=tempnam(sys_get_temp_dir(),'upload_');
        file_put_contents($path,$content);
        $this->tempFiles[]=$path;
        return App::new(
            UploadFile::class,
            name:'demo.txt',
            type:'text/plain',
            size:strlen($content),
            extension:'txt',
            path:$path,
            confirm_dir:$confirm_dir??sys_get_temp_dir()
        );
    }

    /**
     * 测试哈希在移交存储前算好
     *
     * - 存储保存后临时文件不复存在, 此时仍须能拿到哈希
     *
     * @return void
     */
    public function testHashComputedBeforeStorageMovesFile(): void {
        $content='hello-upload-content';
        $file=$this->makeFile($content);
        // 未计算过时信息数组里为空
        $this->assertNull($file->toArray()['hash']);
        $storage=new FakeUploadStorage();
        $file->save($storage);
        // 保存后临时文件已被存储取走, 哈希仍可用
        $this->assertFalse(is_file($file->getTempPath()));
        $this->assertSame(sha1($content),$file->toArray()['hash']);
        $this->assertSame(sha1($content),$file->getHash());
    }

    /**
     * 测试保存路径
     * @return void
     */
    public function testSavePath(): void {
        $file=$this->makeFile('content');
        // 未保存时取路径报错
        try {
            $file->getSavePath();
            $this->fail('未保存时应当抛出异常');
        } catch(UploadException $e) {
            $this->assertSame('文件未保存',$e->getMessage());
        }
        $storage=new FakeUploadStorage();
        $file->save($storage);
        $this->assertSame($storage->saved_to,$file->getSavePath());
    }

    /**
     * 测试设置保存目录时的校验
     * @return void
     */
    public function testSetConfirmDirRejectsMissingDir(): void {
        $file=$this->makeFile('content');
        $missing=sys_get_temp_dir().'/as_missing_'.uniqid();
        $this->expectException(UploadException::class);
        $this->expectExceptionMessage('上传目录不存在或不可写');
        $file->setConfirmDir($missing);
    }

    /**
     * 测试信息数组的字段
     * @return void
     */
    public function testToArrayShape(): void {
        $file=$this->makeFile('content');
        $file->setConfirmName('fixed.txt');
        $info=$file->toArray();
        $this->assertSame(array(
            'name','extension','size','type','hash','confirm_name','save_path'
        ),array_keys($info));
        $this->assertSame('demo.txt',$info['name']);
        $this->assertSame('txt',$info['extension']);
        $this->assertSame(7,$info['size']);
        $this->assertSame('text/plain',$info['type']);
        $this->assertSame('fixed.txt',$info['confirm_name']);
        $this->assertNull($info['save_path']);
    }

    /**
     * 测试解析阶段不创建上传目录
     *
     * - 目录改由存储方在保存时准备, 请求解析不再产生副作用
     *
     * @return void
     */
    public function testUploadFilesDoesNotCreateDir(): void {
        $dir=sys_get_temp_dir().'/as_upload_'.uniqid();
        $files=new UploadFiles($dir,array());
        $this->assertFalse(is_dir($dir));
        $this->assertSame(array(),$files->toArray());
    }

    /**
     * 测试存储方准备保存目录
     * @return void
     */
    public function testStorageCreatesSaveDir(): void {
        $dir=sys_get_temp_dir().'/as_storage_'.uniqid();
        $file=$this->makeFile('content',$dir);
        $storage=new UploadStorage();
        try {
            // CLI下 move_uploaded_file 必然失败, 但目录此时应已被准备
            $storage->save($file);
            $this->fail('CLI下保存应当失败');
        } catch(UploadStorageException $e) {
            $this->assertSame('保存文件失败',$e->getMessage());
        }
        $this->assertTrue(is_dir($dir));
        rmdir($dir);
    }

    /**
     * 测试存储方校验临时文件
     * @return void
     */
    public function testStorageValidatesTempFile(): void {
        $file=$this->makeFile('content');
        unlink($file->getTempPath());
        $storage=new UploadStorage();
        $this->expectException(UploadStorageException::class);
        $this->expectExceptionMessage('文件不存在或不可读');
        $storage->save($file);
    }

}
