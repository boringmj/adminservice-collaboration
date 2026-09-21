<?php

namespace Tests;

use PHPUnit\Framework\TestCase;

use AdminService\Config;
use AdminService\Router\AttributeScanner;

/**
 * 属性路由扫描器测试
 *
 * - 验证扫描规则: 目录约定、文件名即类名、类可自动加载
 */
class AttributeScannerTest extends TestCase {

    /**
     * 临时应用目录
     * @var string|null
     */
    private ?string $tempDir=null;

    /**
     * 每个测试后清理临时目录
     * @return void
     */
    protected function tearDown(): void {
        if($this->tempDir===null)
            return;
        foreach((array)glob($this->tempDir.'/*/controller/*.php') as $file)
            unlink($file);
        foreach((array)glob($this->tempDir.'/*/controller') as $dir)
            rmdir($dir);
        foreach((array)glob($this->tempDir.'/*') as $dir)
            rmdir($dir);
        rmdir($this->tempDir);
        $this->tempDir=null;
    }

    /**
     * 测试扫描出项目中的应用控制器
     * @return void
     */
    public function testScanFindsControllers(): void {
        $classes=(new AttributeScanner())->scan(Config::get('app.path'));
        $this->assertContains(\app\index\controller\Index::class,$classes);
        $this->assertContains(\app\demo\controller\Index::class,$classes);
        $this->assertContains(\app\demo\controller\OrmDemo::class,$classes);
        $this->assertContains(\app\demo\controller\DatabaseDemo::class,$classes);
    }

    /**
     * 测试文件名与类名不一致时该文件被跳过
     * @return void
     */
    public function testScanSkipsFileWithoutMatchingClass(): void {
        $this->tempDir=sys_get_temp_dir().'/scan_'.uniqid();
        mkdir($this->tempDir.'/foo/controller',0777,true);
        // 文件名 NotAClass.php, 而类名为 Other → 推出的类名不存在
        file_put_contents(
            $this->tempDir.'/foo/controller/NotAClass.php',
            "<?php\nnamespace app\\foo\\controller;\nclass Other {}\n"
        );
        $this->assertSame(array(),(new AttributeScanner())->scan($this->tempDir));
    }

}
