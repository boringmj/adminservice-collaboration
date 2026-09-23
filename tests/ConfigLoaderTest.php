<?php

namespace Tests;

use PHPUnit\Framework\TestCase;

use AdminService\Config\Loader;
use AdminService\Config\Repository;
use base\ConfigInterface;

use function array_keys;
use function dirname;
use function file_put_contents;
use function is_array;
use function is_dir;
use function mkdir;
use function rmdir;
use function scandir;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

/**
 * 配置仓储与加载器用例(`AdminService\Config\Repository` / `Loader`)
 *
 * 两段覆盖:
 *  1. `Repository`: 点分键口径(子树、列表下标、子空数组、`null` 视为不存在、字面含 `.` 的键取不到),
 *     并实现 `base\ConfigInterface`
 *  2. `Loader` 的**文件来源**: 目录扫描的顺序、名字规范过滤、**显式清单不扫目录**
 *
 * 用例全部用临时目录造样例, 不依赖仓库里的真实配置(唯一的例外是最后那条集成用例)。
 */
class ConfigLoaderTest extends TestCase {

    /**
     * 建临时配置目录
     *
     * @param array<string,string> $files 文件名 => 内容
     * @return string 目录路径
     */
    private function makeDir(array $files): string {
        $dir=sys_get_temp_dir().'/cfg_'.uniqid('',true);
        mkdir($dir,0755,true);
        foreach($files as $name=>$content)
            file_put_contents($dir.'/'.$name,$content);
        return $dir;
    }

    /**
     * 删临时目录
     *
     * @param string $dir 目录路径
     * @return void
     */
    private function removeDir(string $dir): void {
        if(!is_dir($dir))
            return;
        foreach((array)scandir($dir) as $entry)
            if($entry!=='.'&&$entry!=='..'&&$entry!==false)
                unlink($dir.'/'.$entry);
        rmdir($dir);
    }

    /**
     * 测试: 平坦点分键(子树 / 叶子 / 列表下标 / 缺省值)
     * @return void
     */
    public function testFlatLookup(): void {
        $repo=new Repository(array(
            'app'=>array('debug'=>true,'path'=>'/app'),
            'route'=>array('files'=>array('/routes','/more')),
            'empty'=>array(),
        ));
        $this->assertTrue($repo->get('app.debug'));
        $this->assertSame(array('debug'=>true,'path'=>'/app'),$repo->get('app'),'中间节点也要能取到整棵子树');
        $this->assertSame('/more',$repo->get('route.files.1'),'列表下标也是键');
        $this->assertSame(array(),$repo->get('empty'),'空数组是可达的叶子');
        $this->assertSame('dflt',$repo->get('nope','dflt'));
        $this->assertSame('dflt',$repo->get('app.nope','dflt'),'中途缺段回落默认值');
        $this->assertTrue($repo->has('route.files'));
        $this->assertFalse($repo->has('route.files.9'));
        $this->assertSame(array('app'=>array('debug'=>true,'path'=>'/app'),'route'=>array('files'=>array('/routes','/more')),'empty'=>array()),$repo->all());
    }

    /**
     * 测试: `null` 视为"不存在"(与旧 `Config::get()` 的 `isset` 口径一致), 而 `false`/`0`/`''` 算存在
     * @return void
     */
    public function testNullIsTreatedAsMissing(): void {
        $repo=new Repository(array('a'=>null,'b'=>false,'c'=>0,'d'=>'','e'=>array('f'=>null)));
        $this->assertFalse($repo->has('a'));
        $this->assertSame('dflt',$repo->get('a','dflt'));
        $this->assertSame('dflt',$repo->get('e.f','dflt'),'路径末端为 null 同样回落默认值');
        $this->assertTrue($repo->has('b'));
        $this->assertFalse($repo->get('b','dflt'),'false 不是"不存在"');
        $this->assertSame(0,$repo->get('c','dflt'));
        $this->assertSame('',$repo->get('d','dflt'));
    }

    /**
     * 测试: 字面含 `.` 的键取不到(旧实现按 `.` 逐段下钻), 但仍原样留在 `all()` 里
     * @return void
     */
    public function testLiteralDottedKeyIsUnreachable(): void {
        $repo=new Repository(array('a.b'=>'literal','a'=>array('b'=>'nested')));
        $this->assertSame('nested',$repo->get('a.b'),'按路径逐段下钻, 与旧行为一致');
        $this->assertTrue($repo->has('a.b'));
        $this->assertSame(array('a.b'=>'literal','a'=>array('b'=>'nested')),$repo->all(),'取不到的键不该从 all() 里消失');
    }

    /**
     * 测试: 空仓储与契约实现
     * @return void
     */
    public function testEmptyRepositoryAndContract(): void {
        $repo=new Repository(array());
        $this->assertSame(array(),$repo->all());
        $this->assertSame('dflt',$repo->get('anything','dflt'));
        $this->assertInstanceOf(ConfigInterface::class,$repo);
    }

    /**
     * 测试: 目录加载(键取文件名, 顺序稳定)
     * @return void
     */
    public function testLoadsDirectory(): void {
        $dir=$this->makeDir(array(
            'log.php'=>"<?php return array('path'=>'/log');",
            'app.php'=>"<?php return array('debug'=>false);",
        ));
        try {
            $loader=new Loader($dir);
            $configs=$loader->load();
            $this->assertSame(array('app','log'),array_keys($configs),'按文件名字典序(显式 sort)');
            $this->assertSame('/log',$configs['log']['path']);
            $this->assertSame(array($dir.'/app.php',$dir.'/log.php'),$loader->files());
            $this->assertSame(array(),$loader->diagnostics());
        } finally {
            $this->removeDir($dir);
        }
    }

    /**
     * 测试: 名字不合规的配置文件被跳过, 并且**留下诊断**(旧实现默默跳过)
     * @return void
     */
    public function testNonConformingNameIsSkippedWithDiagnostic(): void {
        $dir=$this->makeDir(array(
            'app.php'=>"<?php return array('ok'=>1);",
            'my-config.php'=>"<?php return array('nope'=>1);",
        ));
        try {
            $loader=new Loader($dir);
            $configs=$loader->load();
            $this->assertSame(array('app'),array_keys($configs));
            $this->assertCount(1,$loader->diagnostics());
            $this->assertStringContainsString('my-config.php',$loader->diagnostics()[0]);
        } finally {
            $this->removeDir($dir);
        }
    }

    /**
     * 测试: 配置文件没有返回数组时收下值并留诊断(不静默)
     * @return void
     */
    public function testNonArrayReturnIsDiagnosed(): void {
        $dir=$this->makeDir(array('weird.php'=>"<?php return 'oops';"));
        try {
            $loader=new Loader($dir);
            $this->assertSame(array('weird'=>'oops'),$loader->load());
            $this->assertStringContainsString('没有返回数组',$loader->diagnostics()[0]);
        } finally {
            $this->removeDir($dir);
        }
    }

    /**
     * 测试: 显式清单只加载清单里的文件(不扫目录), 清单缺失条目要留诊断
     * @return void
     */
    public function testExplicitFileListSkipsDirectoryScan(): void {
        $dir=$this->makeDir(array(
            'app.php'=>"<?php return array('a'=>1);",
            'log.php'=>"<?php return array('b'=>2);",
            'stray.php'=>"<?php return array('c'=>3);",
        ));
        try {
            $loader=new Loader($dir,array('app','log'));
            $configs=$loader->load();
            $this->assertSame(array('app','log'),array_keys($configs),'stray.php 不在清单里, 就不该被加载');
            $this->assertSame(array(),$loader->diagnostics());

            $loader=new Loader($dir,array('app.php','missing'));
            $this->assertSame(array('app'),array_keys($loader->load()),'带 .php 后缀也认');
            $this->assertStringContainsString('清单里的配置文件不存在',$loader->diagnostics()[0]);
        } finally {
            $this->removeDir($dir);
        }
    }

    /**
     * 测试: 仓库真实的 `config/` 走加载器 —— 11 个文件、零诊断、`env()` 已在配置文件里生效
     *
     * - 守的是"配置文件本身没毛病";`.env` 与配置键的对账请在部署前自行核对
     *   (S8 之后运行期不再合并 `.env`: 键写错的表现是 `env()` 取到默认值, 或"必需键"直接报错)
     *
     * @return void
     */
    public function testRealConfigLoadsCleanly(): void {
        $root=dirname(__DIR__);
        $loader=new Loader($root.'/AdminService/config');
        $configs=$loader->load();
        $this->assertCount(11,$configs,'本仓库有 11 个配置文件');
        $this->assertSame(array(),$loader->diagnostics());
        // 这里**直接**走 Loader, 不经 tests/bootstrap.php 的 load_test_config(),
        // 因此 `app.debug` 反映的是本机真实 `.env` 的 `APP_DEBUG` —— 只断言"类型对"(bool),
        // 不断言具体值, 免得用例依赖某台机器的 `.env`
        $this->assertIsBool($configs['app']['debug'],'`env(\'APP_DEBUG\', false)` 取到的是 bool');
        $this->assertTrue(is_array($configs['database']['connections']['default']));
        $this->assertIsInt($configs['database']['connections']['default']['port'],'配置里显式 (int)env(...), 故是 int');
    }

}
