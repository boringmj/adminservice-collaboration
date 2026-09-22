<?php

namespace Tests;

use PHPUnit\Framework\TestCase;

use AdminService\Config\Env;

use function count;
use function file_put_contents;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

/**
 * `.env` 解析器用例(`AdminService\Config\Env`)
 *
 * 覆盖三类:
 *  1. **值语法**: 值含 `=`、`=` 左/右空格、单双引号(含转义)、`export` 前缀、行尾注释、空行、CRLF
 *  2. **类型推断**: `true`/`false`/`null`/整数/浮点/科学计数法, 以及"保留字符串"的边界
 *     (前导 0、超出 int 范围、引号形式)
 *  3. **兼容口径与容错**: 键名大小写不敏感且后写覆盖、无法解析的行进 `errors()` 而不抛异常
 *
 * 这些用例正是计划 S2 要求的清单 —— 旧实现(`Config::load()` 内联 30 行)在"值含 `=`"
 * "`=` 左侧空格""引号""export""行尾注释"上**全都不对**, 故此处逐条固定行为。
 */
class EnvParserTest extends TestCase {

    /**
     * 便捷构造
     *
     * @param string $content `.env` 文本
     * @return Env
     */
    private function env(string $content): Env {
        return new Env($content);
    }

    /**
     * 测试: 值里含 `=` 只在第一个 `=` 处切分(旧实现会被截断)
     * @return void
     */
    public function testValueContainingEquals(): void {
        $env=$this->env("PWD=ab=cd=ef\nDSN=mysql:host=x;dbname=y");
        $this->assertSame('ab=cd=ef',$env->get('pwd'),'值里的 `=` 必须原样保留(旧实现只取第一段之后的第 2 段)');
        $this->assertSame('mysql:host=x;dbname=y',$env->get('DSN'));
    }

    /**
     * 测试: `=` 左右两侧的空格处理
     *
     * - 左侧: 忽略(依 `.env.example` 的既有承诺)
     * - 右侧: 保留(同样是 `.env.example` 的承诺; 要去掉请用引号形式)
     *
     * @return void
     */
    public function testSpacesAroundEquals(): void {
        $env=$this->env("A =VALUE\nB= VALUE\nC  =  VALUE  ");
        $this->assertTrue($env->has('A'),'`=` 左侧的空格必须被忽略, 否则键名会带上尾随空格');
        $this->assertSame('VALUE',$env->get('A'));
        $this->assertSame(' VALUE',$env->get('B'),'`=` 右侧的空格按 .env.example 保留');
        $this->assertSame('  VALUE',$env->get('C'),'行首尾空白去掉, 但 `=` 右侧的保持原样');
    }

    /**
     * 测试: 单/双引号
     *
     * - 引号内的 `#` 不是注释、空白不被裁剪
     * - 引号形式**永远是字符串**(`"true"` 就是字符串, 这是"我要字符串"的逃生口)
     * - 双引号处理 `\n` `\r` `\t` `\"` `\\`, 单引号内一律原样
     *
     * @return void
     */
    public function testQuotes(): void {
        $env=$this->env(implode("\n",array(
            'Q1="a b # c"',
            "Q2='  pad  '",
            'Q3="true"',
            'Q4="123"',
            'Q5="line\nbreak"',
            'Q6="say \"hi\""',
            'Q7="back\\\\slash"',
            "Q8='no\\nescape'",
        )));
        $this->assertSame('a b # c',$env->get('Q1'));
        $this->assertSame('  pad  ',$env->get('Q2'),'引号内的空白原样保留');
        $this->assertSame('true',$env->get('Q3'),'引号形式不做类型推断');
        $this->assertSame('123',$env->get('Q4'));
        $this->assertSame("line\nbreak",$env->get('Q5'));
        $this->assertSame('say "hi"',$env->get('Q6'));
        $this->assertSame('back\\slash',$env->get('Q7'));
        $this->assertSame('no\\nescape',$env->get('Q8'),'单引号内不处理转义');
    }

    /**
     * 测试: `export` 前缀、整行注释、行尾注释
     *
     * - 行尾注释只在 `#` **前有空白**时生效(`va#lue` 里的 `#` 是值的一部分)
     * - 引号值不被行尾注释影响
     *
     * @return void
     */
    public function testExportAndComments(): void {
        $env=$this->env(implode("\n",array(
            'export A=1',
            'EXPORT B=2',
            '# 整行注释',
            '   # 前面有空格的整行注释',
            'C=value # 行尾注释',
            'D=value   # 前面多个空格',
            'E=va#lue',
            'F="a # b" # 行尾注释',
            'G=#开头不是注释',
        )));
        $this->assertSame(1,$env->get('A'),'export 前缀(小写)');
        $this->assertSame(2,$env->get('B'),'export 前缀(大写, 大小写不敏感)');
        $this->assertSame('value',$env->get('C'));
        $this->assertSame('value',$env->get('D'),'注释前的空白不留在值里');
        $this->assertSame('va#lue',$env->get('E'),'`#` 前无空白时不是注释');
        $this->assertSame('a # b',$env->get('F'),'引号值不被行尾注释切开');
        $this->assertSame('#开头不是注释',$env->get('G'),'值以 `#` 开头时不是注释');
    }

    /**
     * 测试: 空行、纯空白行、CRLF、文件末尾无换行
     * @return void
     */
    public function testBlankLinesAndCrlf(): void {
        $env=$this->env("\r\nA=1\r\n\r\n   \r\nB=2\r\n");
        $this->assertSame(array('A'=>1,'B'=>2),$env->all());
        $this->assertSame(array(),$this->env('')->all(),'空文本解析出空表');
        $this->assertSame(array(),$this->env("\n\n\n")->all());
    }

    /**
     * 测试: 类型推断(仅非引号值)
     *
     * - 整数只在"能直接还原"时才转: 前导 `0` 与超出 int 范围的一律保留字符串
     * - `+7` 不转(`-` 号认, `+` 号不认) —— 刻意的窄口径, 避免与字符串混淆
     *
     * @return void
     */
    public function testTypeInference(): void {
        $env=$this->env(implode("\n",array(
            'T1=true','T2=TRUE','T3=True','T4=false','T5=FALSE',
            'T6=null','T7=NULL','T8=None',
            'I1=123','I2=-7','I3=+7','I4=0012','I5=99999999999999999999','I6=-0',
            'F1=1.5','F2=1.','F3=.5','F4=-0.25','F5=1e3','F6=1E-3','F7=1.5e2',
            'S1=abc','S2=1 2','S3=Infinity','S4=0x1A','S5=','S6=08',
        )));
        $this->assertTrue($env->get('T1'));
        $this->assertTrue($env->get('T2'));
        $this->assertTrue($env->get('T3'));
        $this->assertFalse($env->get('T4'));
        $this->assertFalse($env->get('T5'));
        $this->assertNull($env->get('T6'));
        $this->assertNull($env->get('T7'));
        $this->assertSame('None',$env->get('T8'),'不是标准的 null 写法就保留字符串');
        $this->assertSame(123,$env->get('I1'));
        $this->assertSame(-7,$env->get('I2'));
        $this->assertSame('+7',$env->get('I3'));
        $this->assertSame('0012',$env->get('I4'),'前导 0 保留字符串, 免得被当成八进制或丢零');
        $this->assertSame('99999999999999999999',$env->get('I5'),'超出 int 范围不静默截断');
        $this->assertSame('-0',$env->get('I6'));
        $this->assertSame(1.5,$env->get('F1'));
        $this->assertSame(1.0,$env->get('F2'));
        $this->assertSame(0.5,$env->get('F3'));
        $this->assertSame(-0.25,$env->get('F4'));
        $this->assertSame(1000.0,$env->get('F5'));
        $this->assertSame(0.001,$env->get('F6'));
        $this->assertSame(150.0,$env->get('F7'));
        $this->assertSame('abc',$env->get('S1'));
        $this->assertSame('1 2',$env->get('S2'));
        $this->assertSame('Infinity',$env->get('S3'));
        $this->assertSame('0x1A',$env->get('S4'));
        $this->assertSame('',$env->get('S5'),'`KEY=` 是空字符串(与旧实现一致)');
        $this->assertSame('08',$env->get('S6'));
    }

    /**
     * 测试: 键名大小写不敏感 + 后写覆盖前写(旧的 `strtolower()` 语义等价)
     * @return void
     */
    public function testCaseInsensitiveOverride(): void {
        $env=$this->env(implode("\n",array(
            'APP.DEBUG=true',
            'app.debug=false',
        )));
        $this->assertSame(1,count($env->all()),'同名(忽略大小写)的键只保留一个');
        $this->assertFalse($env->get('app.debug'),'后写的覆盖先写的');
        $this->assertFalse($env->get('App.Debug'),'查找不区分大小写');
        $this->assertTrue($env->has('APP.DEBUG'),'用先写的写法也能查到');
        $this->assertSame('app.debug',array_keys($env->all())[0],'保留后写的写法');
    }

    /**
     * 测试: 无法解析的行进 `errors()`(不抛异常), 能抢救的值仍保留
     * @return void
     */
    public function testMalformedLinesAreCollected(): void {
        $env=$this->env(implode("\n",array(
            'OK=1',
            'NOEQUALS',
            '=bad',
            'UNCLOSED="abc',
            'EXTRA="abc" junk',
        )));
        $this->assertSame(1,$env->get('OK'),'合法行不受影响');
        $this->assertSame('"abc',$env->get('UNCLOSED'),'引号未闭合时按普通字符串保留');
        $this->assertSame('abc',$env->get('EXTRA'),'引号闭合, 其后的多余内容被忽略');
        $this->assertCount(4,$env->errors());
        foreach($env->errors() as $error)
            $this->assertStringContainsString('行',$error,'报错信息要带行号便于定位');
    }

    /**
     * 测试: 多字节内容不被腰斩
     *
     * - 背景(实测踩过的坑): 初版按 `preg_split('/\R/')` 切行, 无 `u` 修饰时 `\R` 会把
     *   `\x85`(NEL) 也当换行, 而 `\x85` 正好落在中文的 UTF-8 字节里(`关` = `E5 85 B3`)——
     *   于是中文注释行被切成"`# ` + 半个字", 后者又被当成畸形行。故改用按 `\n` 切。此用例把这条固定下来。
     *
     * @return void
     */
    public function testMultibyteContentSurvives(): void {
        $env=$this->env(implode("\n",array(
            '# 中文注释',
            'GREETING=你好, 世界',
            'CN_WITH_EQUALS=键=值',
        )));
        $this->assertSame('你好, 世界',$env->get('GREETING'));
        $this->assertSame('键=值',$env->get('CN_WITH_EQUALS'),'多字节值同样只在第一个 `=` 处切分');
        $this->assertSame(array(),$env->errors(),'中文注释行不该被当成畸形行');
    }

    /**
     * 测试: 取值语义(默认值 / 不存在的键 / 值为 null 时 has 仍为真)
     * @return void
     */
    public function testLookupSemantics(): void {
        $env=$this->env("N=null\nE=");
        $this->assertSame('dflt',$env->get('nope','dflt'));
        $this->assertFalse($env->has('nope'));
        $this->assertTrue($env->has('N'));
        $this->assertNull($env->get('N','dflt'),'键存在且值为 null 时返回 null, 不回落默认值');
        $this->assertTrue($env->has('E'));
        $this->assertSame('',$env->get('E','dflt'));
    }

    /**
     * 测试: 从文件构造(含文件不存在的情形)
     * @return void
     */
    public function testFromFile(): void {
        $file=(string)tempnam(sys_get_temp_dir(),'env_test_');
        file_put_contents($file,"# 临时\nA=1\nB=\"x y\"\n");
        try {
            $env=Env::fromFile($file);
            $this->assertSame(1,$env->get('A'));
            $this->assertSame('x y',$env->get('B'));
            $this->assertSame($file,$env->path());
            $this->assertSame(array(),$env->errors());
        } finally {
            unlink($file);
        }
        $missing=Env::fromFile($file.'-not-exist');
        $this->assertSame(array(),$missing->all(),'文件不存在时按空配置处理, 不抛异常');
        $this->assertCount(1,$missing->errors());
    }

    /**
     * 测试: 全局 `env()` 助手确实读到了项目的 `.env`
     *
     * - 为什么要有这条: 助手里的路径是硬编码的相对上溯, 写错一层会**静默**全部返回默认值
     *   (实测: 初版写成 `dirname(__DIR__,2)`, 直接指到仓库外, 所有键都变 NULL 却不报错)。
     *   这里用"从 tests/ 另算一次项目根"来交叉验证, 与助手内部的算法相互独立。
     * - 比对时**跳过口令类键的值**(只比对键名集合), 免得断言失败时把口令打印进输出
     *
     * @return void
     */
    public function testGlobalEnvHelperReadsProjectEnv(): void {
        $file=dirname(__DIR__).'/.env';
        if(!is_file($file))
            $this->markTestSkipped('本机没有 .env(未纳入版本库), 跳过助手路径校验');
        $expected=Env::fromFile($file);
        $this->assertNotSame(array(),$expected->all(),'前提: 本机 .env 应当有内容');
        foreach($expected->all() as $key=>$value) {
            $this->assertTrue(env($key)!==null||$value===null,'env() 助手读不到 `'.$key.'`(路径算错了?)');
            if(preg_match('/password|secret|token/i',(string)$key)===1)
                continue;
            $this->assertSame($value,env($key),'env() 助手的取值与直接解析 .env 不一致: '.$key);
        }
    }

    /**
     * 测试: 真实的 `.env.example` 形状(点分键 + 布尔 + 注释)能解析出预期结果
     *
     * - 这里用内联文本而不是读仓库文件, 免得用例依赖某个具体版本的文件内容
     *
     * @return void
     */
    public function testRealWorldShape(): void {
        $env=$this->env(implode("\n",array(
            '# .env',
            'request.default.type=json',
            'route.default.app=app',
            '',
            '# 关闭调试模式',
            'app.debug=false',
            'database.connections.default.password=fW44=KD=01',
        )));
        $this->assertSame('json',$env->get('request.default.type'),'点分键就是普通键');
        $this->assertSame('app',$env->get('route.default.app'));
        $this->assertFalse($env->get('app.debug'));
        $this->assertSame('fW44=KD=01',$env->get('database.connections.default.password'),'口令里的 `=` 不再被截断');
        $this->assertSame(array(),$env->errors());
    }

}
