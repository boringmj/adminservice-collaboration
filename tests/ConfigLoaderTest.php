<?php

namespace Tests;

use PHPUnit\Framework\TestCase;

use AdminService\Config;
use AdminService\Config\Env;
use AdminService\Config\Loader;
use AdminService\Config\Repository;
use AdminService\exception\ConfigException;
use base\ConfigInterface;

use function file_put_contents;
use function is_dir;
use function is_file;
use function mkdir;
use function rmdir;
use function scandir;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

/**
 * 配置仓储与加载器用例(`AdminService\Config\Repository` / `Loader`)
 *
 * 三段覆盖:
 *  1. `Repository`: 点分键口径与旧 `Config::get()` **等价**(子树、列表下标、子空数组、`null` 视为不存在、
 *     字面含 `.` 的键取不到), 并实现 `base\ConfigInterface`
 *  2. `Loader` 的**文件来源**: 目录扫描的顺序、名字规范过滤、**显式清单不扫目录**(S6 编译缓存走这条路)
 *  3. `Loader` 的 **`.env` 合并**过渡行为: 点分键覆盖/新建、键名小写化、**值按字符串写入**、
 *     仅布尔节点套旧换算、三种未知键策略、路径冲突不再留半个节点
 *
 * 全部用临时目录造样例, 不依赖仓库里的真实配置与 `.env`(唯一的例外是最后那条集成用例, 且缺 `.env` 时跳过)。
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
     * 造一个加载器(配置目录 + 可选 .env 路径)
     *
     * @param string $dir 配置目录
     * @param string|null $env_file `.env` 路径
     * @param array<string>|null $files 显式清单
     * @return Loader
     */
    private function loader(string $dir,?string $env_file=null,?array $files=null): Loader {
        $loader=new Loader($dir,$files);
        if($env_file!==null)
            $loader->setEnvFile($env_file);
        return $loader;
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
        $repo=new Repository();
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
            $loader=$this->loader($dir);
            $configs=$loader->load();
            $this->assertSame(array('app','log'),array_keys($configs),'按文件名字典序(显式 sort, 不靠 glob 的平台行为)');
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
            $loader=$this->loader($dir);
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
            $loader=$this->loader($dir);
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
            $loader=$this->loader($dir,null,array('app','log'));
            $configs=$loader->load();
            $this->assertSame(array('app','log'),array_keys($configs),'stray.php 不在清单里, 就不该被加载');
            $this->assertSame(array(),$loader->diagnostics());

            $loader=$this->loader($dir,null,array('app.php','missing'));
            $this->assertSame(array('app'),array_keys($loader->load()),'带 .php 后缀也认');
            $this->assertStringContainsString('清单里的配置文件不存在',$loader->diagnostics()[0]);
        } finally {
            $this->removeDir($dir);
        }
    }

    /**
     * 测试: `.env` 合并 —— 点分键覆盖已有值、键名大小写与小写化映射
     * @return void
     */
    public function testEnvMergeOverridesExistingValues(): void {
        $dir=$this->makeDir(array(
            'app.php'=>"<?php return array('debug'=>false);",
            'database.php'=>"<?php return array('connections'=>array('default'=>array('host'=>'localhost','password'=>'')));",
        ));
        $env=$this->makeDir(array('only.env'=>"APP.DEBUG=true\ndatabase.connections.default.password=ab=cd\n"));
        try {
            $loader=$this->loader($dir,$env.'/only.env');
            $configs=$loader->load();
            $this->assertTrue($configs['app']['debug'],'大写的 .env 键映射到小写配置键, 且布尔节点套旧换算');
            $this->assertSame('ab=cd',$configs['database']['connections']['default']['password'],'值里的 `=` 不再被截断');
            $this->assertSame(array(),$loader->diagnostics(),'真实用法下不应产生任何诊断');
            $this->assertInstanceOf(Env::class,$loader->env());
        } finally {
            $this->removeDir($dir);
            $this->removeDir($env);
        }
    }

    /**
     * 测试: `.env` 的值一律按**字符串**写入(只有布尔节点例外) —— 旧的"值只能是字符串和布尔值"
     * @return void
     */
    public function testEnvValuesAreWrittenAsStrings(): void {
        $dir=$this->makeDir(array(
            'log.php'=>"<?php return array('dir_mode'=>493,'path'=>'');",
            'str.php'=>"<?php return array('flag'=>'x');",
        ));
        $env=$this->makeDir(array('only.env'=>"log.dir_mode=755\nstr.flag=1e3\n"));
        try {
            $configs=$this->loader($dir,$env.'/only.env')->load();
            $this->assertSame('755',$configs['log']['dir_mode'],'数字不做类型推断, 原样按字符串写入(旧行为)');
            $this->assertSame('1e3',$configs['str']['flag']);
        } finally {
            $this->removeDir($dir);
            $this->removeDir($env);
        }
    }

    /**
     * 测试: 布尔节点的旧换算口径(false/null/0 → false, 其余(含空串) → true)
     * @return void
     */
    public function testBooleanCoercionOnBooleanNode(): void {
        $dir=$this->makeDir(array('app.php'=>"<?php return array('t'=>true,'f'=>false);"));
        $env=$this->makeDir(array('only.env'=>implode("\n",array(
            'app.t=0','app.f=yes',
        ))));
        try {
            $configs=$this->loader($dir,$env.'/only.env')->load();
            $this->assertFalse($configs['app']['t'],'原值为 bool 时 `0` 算假');
            $this->assertTrue($configs['app']['f'],'原值为 bool 时 `yes` 算真');
        } finally {
            $this->removeDir($dir);
            $this->removeDir($env);
        }
        $dir2=$this->makeDir(array('app.php'=>"<?php return array('t'=>true,'f'=>false);"));
        $env2=$this->makeDir(array('only.env'=>"app.t=\napp.f=null\n"));
        try {
            $configs=$this->loader($dir2,$env2.'/only.env')->load();
            $this->assertTrue($configs['app']['t'],'空串算真 —— 旧实现的口径就是如此, 这里如实固定');
            $this->assertFalse($configs['app']['f'],'`null` 算假');
        } finally {
            $this->removeDir($dir2);
            $this->removeDir($env2);
        }
    }

    /**
     * 测试: 未知键的三种策略(create 是默认, 即旧行为)
     * @return void
     */
    public function testUnknownKeyPolicies(): void {
        $dir=$this->makeDir(array('app.php'=>"<?php return array('debug'=>false);"));
        $env=$this->makeDir(array('only.env'=>"database.default.host=x\n"));

        try {
            $loader=$this->loader($dir,$env.'/only.env');
            $configs=$loader->load();
            $this->assertSame('x',$configs['database']['default']['host'],'create(默认): 按旧行为新建节点, 行为不变');
            $this->assertStringContainsString('在配置里不存在',$loader->diagnostics()[0]);

            $loader=$this->loader($dir,$env.'/only.env');
            $loader->setUnknownKeyPolicy(Loader::UNKNOWN_KEY_IGNORE);
            $configs=$loader->load();
            $this->assertArrayNotHasKey('database',$configs,'ignore: 不新建节点');
            $this->assertStringContainsString('已按 ignore 策略忽略',$loader->diagnostics()[0]);

            $loader=$this->loader($dir,$env.'/only.env');
            $loader->setUnknownKeyPolicy(Loader::UNKNOWN_KEY_THROW);
            $this->expectException(ConfigException::class);
            $this->expectExceptionCode(100901);
            $loader->load();
        } finally {
            $this->removeDir($dir);
            $this->removeDir($env);
        }
    }

    /**
     * 测试: 未知键策略取值非法要立刻报错(而不是静默退回默认)
     * @return void
     */
    public function testInvalidPolicyIsRejected(): void {
        $this->expectException(ConfigException::class);
        $this->expectExceptionCode(100902);
        (new Loader(sys_get_temp_dir()))->setUnknownKeyPolicy('nope');
    }

    /**
     * 测试: 路径中途遇到标量 → 记诊断并跳过该行(旧实现会留半个节点), 不破坏原配置
     * @return void
     */
    public function testScalarMidPathIsDiagnosedNotCorrupted(): void {
        $dir=$this->makeDir(array('a.php'=>"<?php return array('b'=>'scalar');"));
        $env=$this->makeDir(array('only.env'=>"a.b.c=1\n"));
        try {
            $loader=$this->loader($dir,$env.'/only.env');
            $configs=$loader->load();
            $this->assertSame('scalar',$configs['a']['b'],'原值必须保持不动');
            // 该行会先后触发两条诊断: 先"路径在配置里不存在", 再"中途段已是标量"
            $this->assertStringContainsString('与现有配置冲突',implode("\n",$loader->diagnostics()));
        } finally {
            $this->removeDir($dir);
            $this->removeDir($env);
        }
    }

    /**
     * 测试: `.env` 用标量覆盖整棵子树 → 照旧覆盖, 但要留诊断
     * @return void
     */
    public function testArrayOverwriteIsDiagnosed(): void {
        $dir=$this->makeDir(array('a.php'=>"<?php return array('b'=>array('c'=>1));"));
        $env=$this->makeDir(array('only.env'=>"a.b=flat\n"));
        try {
            $loader=$this->loader($dir,$env.'/only.env');
            $configs=$loader->load();
            $this->assertSame('flat',$configs['a']['b']);
            $this->assertStringContainsString('覆盖了配置里的整棵子树',$loader->diagnostics()[0]);
        } finally {
            $this->removeDir($dir);
            $this->removeDir($env);
        }
    }

    /**
     * 测试: `.env` 里解析不了的行 → 诊断里带 `.env` 前缀与行号
     * @return void
     */
    public function testEnvParseErrorsBecomeDiagnostics(): void {
        $dir=$this->makeDir(array('app.php'=>"<?php return array('debug'=>false);"));
        $env=$this->makeDir(array('only.env'=>"NOT_A_PAIR\napp.debug=false\n"));
        try {
            $loader=$this->loader($dir,$env.'/only.env');
            $loader->load();
            $this->assertStringStartsWith('.env 第 1 行',$loader->diagnostics()[0]);
            $this->assertCount(1,$loader->env()->errors(),'解析器的错误可从 env() 取到');
        } finally {
            $this->removeDir($dir);
            $this->removeDir($env);
        }
    }

    /**
     * 测试: `.env` 不存在时按空配置处理(留诊断, 不抛异常)
     * @return void
     */
    public function testMissingEnvFileIsNotFatal(): void {
        $dir=$this->makeDir(array('app.php'=>"<?php return array('debug'=>false);"));
        try {
            $loader=$this->loader($dir,$dir.'/no-such.env');
            $this->assertFalse($loader->load()['app']['debug'],'配置本身照常, 只是没有覆盖');
            $this->assertStringContainsString('不存在',$loader->diagnostics()[0]);
        } finally {
            $this->removeDir($dir);
        }
    }

    /**
     * 测试: 仓库真实的 `config/` + `.env` 走新加载器 —— 与旧 `Config::load()` 的输出**完全相同**
     *
     * - 这是 S3"行为不变"的证据: 用真实输入对照旧实现, 而不是靠人工推断
     * - 缺 `.env` 时跳过(仓库里 `.env` 不入库)
     * - 用 `===` 求值后只断言**布尔结果**: 配置数组里含真实口令, 断言失败时不能让 PHPUnit 把它打印出来
     * - ⚠ 本用例依赖 `Config::load()` 这条旧路径, **S4/S5 把它改成门面后就该删掉**
     *   (那时"行为不变"由 `tools/baseline/snapshot.php --check` 继续守着)
     *
     * @return void
     */
    public function testRealConfigMatchesLegacyLoader(): void {
        $root=dirname(__DIR__);
        if(!is_file($root.'/.env'))
            $this->markTestSkipped('本机没有 .env(未纳入版本库), 跳过新旧对照');
        Config::load();
        $legacy=Config::all();

        $loader=new Loader($root.'/AdminService/config');
        $loader->setEnvFile($root.'/.env');
        $new=$loader->load();

        $this->assertCount(11,$new,'本仓库有 11 个配置文件');
        $this->assertTrue($legacy===$new,'新加载器的输出与旧 Config::load() 不一致(含类型与层级)');
        $this->assertSame(array(),$loader->diagnostics(),'真实输入下不该产生任何诊断(含"键名写错"的怀疑)');
    }

}
