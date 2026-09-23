<?php

namespace Tests;

use PHPUnit\Framework\TestCase;

use AdminService\Config\Env;
use AdminService\Config\Repository;
use AdminService\exception\ConfigException;

use function implode;
use function is_array;

/**
 * `.env` 路径键覆盖的用例(`Repository::get/all/file`)
 *
 * 机制(2026-09-23 定):`.env` 里的**小写点分路径键**覆盖同名配置项 ——
 * 用意是下游项目**不必改框架的配置文件**就能覆盖任何一项, 于是上游更新配置不会与下游冲突。
 *
 * 三条边界必须钉住(它们都是刻意的):
 *  1. **只覆盖已存在的路径**, 且**绝不新建节点**(配置树的结构只由 `config/*.php` 决定)
 *  2. **只覆盖标量叶子**(路径指向数组时忽略覆盖)
 *  3. **类型跟着配置文件里那个值的类型走**(文件里是 int 就转 int, 否则 `port` 会变成字符串)
 */
class ConfigOverrideTest extends TestCase {

    /**
     * 造一份样例配置树
     *
     * @return array<string,mixed>
     */
    private function configs(): array {
        return array(
            'app'=>array('debug'=>false,'name'=>'demo'),
            'database'=>array('connections'=>array('default'=>array(
                'host'=>'localhost',
                'port'=>3306,
                'password'=>'',
            ))),
            'route'=>array('files'=>array('/routes')),
        );
    }

    /**
     * 造一份 `.env`
     *
     * @param string $content `.env` 文本
     * @return Env
     */
    private function env(string $content): Env {
        return new Env($content);
    }

    /**
     * 测试: 覆盖生效, 且**类型跟着配置文件里的值**
     * @return void
     */
    public function testOverrideKeepsFileType(): void {
        $repo=new Repository($this->configs(),$this->env(implode("\n",array(
            'database.connections.default.host=db.example.com',
            'database.connections.default.port=13306',
            'app.debug=true',
        ))));
        $this->assertSame('db.example.com',$repo->get('database.connections.default.host'));
        $this->assertSame(13306,$repo->get('database.connections.default.port'),'文件里是 int, 覆盖值必须也转成 int');
        $this->assertTrue($repo->get('app.debug'),'文件里是 bool, 按 false/null/0/空 → false, 其余 → true 折算');
        $this->assertSame('demo',$repo->get('app.name'),'没被覆盖的照旧');
    }

    /**
     * 测试: **路径不存在时忽略覆盖**, 且不会在配置树里新建节点
     * @return void
     */
    public function testOverrideIgnoresUnknownPathAndNeverCreatesNodes(): void {
        $repo=new Repository($this->configs(),$this->env(implode("\n",array(
            'database.default.host=typo.example.com',
            'APP_DEBUG=true',
            'brand.new.key=1',
        ))));
        $this->assertNull($repo->get('brand'),'不存在的路径不许被造出来(虚空节点)');
        $this->assertFalse($repo->has('brand'));
        $this->assertNull($repo->get('database.default'),'少写一层同样不许被造出来');
        $this->assertSame('localhost',$repo->get('database.connections.default.host'),'因此原值不变');
        $this->assertSame('demo',$repo->get('app.name'));
        $this->assertArrayNotHasKey('brand',$repo->all());
        $this->assertArrayNotHasKey('default',$repo->all()['database'],'`all()` 也不许出现新节点');
        // 大写键(如 APP_DEBUG)在配置树里没有同名路径 → 天然不会被当成覆盖
        $this->assertFalse($repo->get('app.debug'));
    }

    /**
     * 测试: 路径指向数组时忽略覆盖(只覆盖标量叶子)
     * @return void
     */
    public function testOverrideIgnoresArrayPath(): void {
        $repo=new Repository($this->configs(),$this->env('route.files=/nope'));
        $this->assertSame(array('/routes'),$repo->get('route.files'),'数组路径不会被字符串覆盖');
        $this->assertSame(array('/routes'),$repo->all()['route']['files']);
    }

    /**
     * 测试: `all()` 给的是**生效值**, 且只在已存在的路径上写回
     * @return void
     */
    public function testAllReturnsEffectiveValues(): void {
        $repo=new Repository($this->configs(),$this->env('app.name=overridden'));
        $all=$repo->all();
        $this->assertSame('overridden',$all['app']['name']);
        $this->assertSame('localhost',$all['database']['connections']['default']['host']);
        $this->assertTrue(is_array($all['route']['files']),'未涉及的部分原样保留');
    }

    /**
     * 测试: `config_raw()` 只看配置文件(与被覆盖后的 `get()` 对照)
     * @return void
     */
    public function testFileBypassesOverride(): void {
        $repo=new Repository($this->configs(),$this->env('app.name=overridden'));
        $this->assertSame('overridden',$repo->get('app.name'));
        $this->assertSame('demo',$repo->config_raw('app.name'),'`config_raw()` 给的是配置文件里的原值');
        $this->assertSame('dflt',$repo->config_raw('nope','dflt'));
    }

    /**
     * 测试: 没有 `.env` 快照时(第三个参数不传)只读配置文件
     * @return void
     */
    public function testWithoutEnvSnapshotNoOverride(): void {
        $repo=new Repository($this->configs());
        $this->assertSame('demo',$repo->get('app.name'));
        $this->assertSame($this->configs(),$repo->all());
    }

    /**
     * 测试: 覆盖值与配置文件里的值类型不符时抛异常(不静默保留文件值)
     *
     * - int / float 项: 覆盖值不是数字
     * - string 项: 覆盖值是 bool(只有 `true`/`false`/`null` 会被 `Env` 转成非字符串)
     *
     * @return void
     */
    public function testUncastableOverrideThrows(): void {
        foreach(array('database.connections.default.port'=>'not-a-number','app.name'=>'true') as $path=>$value) {
            try {
                new Repository($this->configs(),$this->env($path.'='.$value));
                $this->fail('类型不符应当抛 ConfigException: '.$path.'='.$value);
            } catch(ConfigException $e) {
                $this->assertStringContainsString($path,$e->getMessage());
                $this->assertStringContainsString($value,$e->getMessage());
            }
        }
    }

    /**
     * 测试: `.env` 里写 `null` 的处理(各类型一致, 不留"假值")
     *
     * - bool 项: `null` → `false`(与布尔折算一致)
     * - 其余类型: **忽略这次覆盖、保留文件值** —— 因为 null 表达不了字符串/数字,
     *   硬转只会给出 `''` 或 `0` 这类假值(`''` 与 `0` 都在 `isset` 意义上是"有值", 更危险)
     *
     * @return void
     */
    public function testNullOverrideHandling(): void {
        $repo=new Repository($this->configs(),$this->env(implode("\n",array(
            'app.debug=null',
            'app.name=null',
            'database.connections.default.port=null',
        ))));
        $this->assertFalse($repo->get('app.debug'),'bool 项: null → false');
        $this->assertSame('demo',$repo->get('app.name'),'字符串项: 忽略覆盖, 保留文件值(不给空串)');
        $this->assertSame(3306,$repo->get('database.connections.default.port'),'int 项: 忽略覆盖, 保留文件值(不给 null/0)');
    }


    /**
     * 测试: **运行时写入优先于一切** —— 盖得过 `.env` 的路径键, 也盖得过配置文件
     *
     * - 用途: 临时改一项而不必重写整份配置;而且**其它键的 `.env` 覆盖照旧生效**(只加一层, 不拆原有那层)
     *
     * @return void
     */
    public function testRuntimeValueWinsOverEverything(): void {
        $repo=new Repository($this->configs(),$this->env(implode("\n",array(
            'app.name=from-env',
            'database.connections.default.port=13306',
            'database.connections.default.host=from-env-host',
            'database.connections.default.password=from-env-pwd',
        ))));
        $repo->put('app.name','from-runtime');
        $repo->put('database.connections.default.port',2222);
        $this->assertSame('from-runtime',$repo->get('app.name'),'运行时写入盖过 .env 的路径键');
        $this->assertSame(2222,$repo->get('database.connections.default.port'),'运行时写入原样给出(不做类型转换 —— 它是代码写的, 不是解析来的)');
        // 关键对照: 同一份仓储里, **没被运行时写入碰过**的键, `.env` 覆盖照旧生效(证明是"加一层", 不是拆了 `.env` 那层)
        $this->assertSame('from-env-host',$repo->get('database.connections.default.host'));
        $this->assertSame('from-env-pwd',$repo->get('database.connections.default.password'));
    }

    /**
     * 测试: `put()` 只允许写在已存在的配置项上(不存在的路径是代码写错, 直接抛)
     * @return void
     */
    public function testPutOnUnknownPathThrows(): void {
        $repo=new Repository($this->configs());
        try {
            $repo->put('brand.new.key',1);
            $this->fail('不存在的路径应当抛 ConfigException');
        } catch(\AdminService\exception\ConfigException $e) {
            $this->assertStringContainsString('brand.new.key',$e->getMessage());
        }
    }

    /**
     * 测试: 覆盖要体现在**任意层级的父路径**上(不只是最靠近叶子的那一层)
     * @return void
     */
    public function testAnyAncestorReflectsChildOverride(): void {
        $repo=new Repository($this->configs(),$this->env('database.connections.default.host=db.example.com'));
        $this->assertSame('db.example.com',$repo->get('database')['connections']['default']['host'],'顶层子树里也要体现');
        $this->assertSame('db.example.com',$repo->get('database.connections')['default']['host'],'中间层同样');
        $this->assertSame('db.example.com',$repo->all()['database']['connections']['default']['host']);
        $this->assertSame('demo',$repo->get('app')['name'],'没被覆盖的部分照旧');
    }

    /**
     * 测试: `config_raw()` 那层**冻结** —— `.env` 覆盖与运行时写入都碰不到它
     * @return void
     */
    public function testFileLayerStaysFrozen(): void {
        $repo=new Repository($this->configs(),$this->env('database.connections.default.host=from-env'));
        $repo->put('database.connections.default.port',2222);
        $this->assertSame('from-env',$repo->get('database.connections.default.host'));
        $this->assertSame('localhost',$repo->config_raw('database.connections.default.host'),'`.env` 覆盖不影响文件层');
        $this->assertSame(3306,$repo->config_raw('database.connections.default.port'),'运行时写入不影响文件层');
        $this->assertSame('localhost',$repo->config_raw('database.connections.default')['host'],'整段读也一样');
        $this->assertSame(3306,$repo->config_raw('database.connections.default')['port']);
    }

    /**
     * 测试: `env()` 那层也不受影响 —— `get()` / `config_raw()` / `env()` 是三条独立通道
     * @return void
     */
    public function testEnvLayerUnaffectedByOthers(): void {
        $repo=new Repository($this->configs(),$this->env("database.connections.default.port=13306\nRAW=raw-value"));
        $repo->put('database.connections.default.port',2222);
        $this->assertSame(2222,$repo->get('database.connections.default.port'),'生效值: 运行时写入');
        $this->assertSame(3306,$repo->config_raw('database.connections.default.port'),'文件层: 原值');
        $this->assertSame('13306',$repo->env('database.connections.default.port'),'`.env` 层: 原样字符串(不折算)');
        $this->assertSame('raw-value',$repo->env('RAW'));
    }

    /**
     * 测试: 值为 `null` 的配置项**也是一个值**(不是"不存在")
     *
     * - `has()` 为 true、`get()` 给 `null` 而不回落默认值(与 PHP 的 `array_key_exists` 口径一致;
     *   旧实现用 `isset`, 会把 null 项当成"不存在")
     * - 因此它还能被 `.env` 路径键覆盖、能被 `put()` 就地改 —— 这些都建立在"键存在"之上
     *
     * @return void
     */
    public function testNullValuedItemCountsAsPresent(): void {
        $repo=new Repository(array('a'=>array('b'=>null,'c'=>1)));
        $this->assertTrue($repo->has('a.b'),'键存在, 值为 null');
        $this->assertNull($repo->get('a.b'),'给 null, 不是默认值');
        $this->assertNull($repo->get('a.b','DFLT'),'显式声明的 null 不该被默认值顶掉');
        $this->assertNull($repo->config_raw('a.b'),'文件层同样是 null');
        $this->assertNull($repo->all()['a']['b']);
        // 能就地改(旧口径会抛"不存在")
        $repo->put('a.b','from-runtime');
        $this->assertSame('from-runtime',$repo->get('a.b'));
        // 也能被 `.env` 路径键覆盖;文件值是 null 时类型无从跟随 → 原样给出
        $repo_with_env=new Repository(array('a'=>array('b'=>null)),$this->env('a.b=from-env'));
        $this->assertSame('from-env',$repo_with_env->get('a.b'));
        // 对照: `.env` 里写 null 是"这一项没有值"(那是 `Env` 层的语义), 不覆盖文件里的值
        $repo_env_null=new Repository(array('a'=>array('b'=>'x')),$this->env('a.b=null'));
        $this->assertSame('x',$repo_env_null->get('a.b'));
    }

    /**
     * 测试: 读**数组节点**时, 它下面的路径键覆盖要合并进去(否则"读整块配置"的消费方看不到覆盖)
     *
     * - 反证口径: `get('database.connections.default')` 与 `get('database.connections.default.host')`
     *   必须是同一个答案 —— 数据库层正是读前者(`DatabaseConfig::connection()`), 两者不一致时
     *   `.env` 里覆盖连接字段等于没覆盖
     *
     * @return void
     */
    public function testArrayNodeMergesChildOverrides(): void {
        $repo=new Repository($this->configs(),$this->env(implode("\n",array(
            'database.connections.default.host=db.example.com',
            'database.connections.default.port=13306',
        ))));
        $node=$repo->get('database.connections.default');
        $this->assertSame('db.example.com',$node['host'],'数组节点里要体现 `.env` 的覆盖');
        $this->assertSame(13306,$node['port'],'类型同样跟着配置文件里的值(与叶子口径一致)');
        $this->assertSame(array('host'=>'db.example.com','port'=>13306,'password'=>''),$node);
        $this->assertSame($repo->all()['database']['connections']['default'],$node,'与 all() 里那份也一致');
        $this->assertSame('db.example.com',$repo->get('database.connections.default.host'),'叶子读法同样一致');
        $this->assertSame('demo',$repo->get('app.name'),'没被覆盖的部分照旧');
    }

    /**
     * 测试: 数组节点也要合并**运行时写入**, 且它仍然赢过 `.env`
     * @return void
     */
    public function testArrayNodeMergesRuntimeValues(): void {
        $repo=new Repository($this->configs(),$this->env('database.connections.default.host=from-env'));
        $repo->put('database.connections.default.port',2222);
        $node=$repo->get('database.connections.default');
        $this->assertSame(2222,$node['port'],'运行时写入要进数组节点');
        $this->assertSame('from-env',$node['host'],'同层的 `.env` 覆盖照旧生效');
        $repo->put('database.connections.default.host','from-runtime');
        $this->assertSame('from-runtime',$repo->get('database.connections.default')['host'],'同一项上运行时写入赢过 `.env`');
    }

    /**
     * 测试: 该路径下**没有任何覆盖/运行时写入**时, 数组节点原样返回(不物化)
     *
     * - 注: 数组是值类型, "有没有复制一份"在外部不可观测;这里断言的是语义(与文件树一致),
     *   零开销那一层由实现里的 `$coveredParents` 保证
     *
     * @return void
     */
    public function testArrayNodeWithoutOverridesStaysFileTree(): void {
        $repo=new Repository($this->configs(),$this->env('app.name=from-env'));
        $this->assertSame($this->configs()['database'],$repo->get('database'),'未涉及的数组节点与文件树一致');
        $this->assertSame($this->configs()['database'],$repo->config_raw('database'));
    }

    /**
     * 测试: **没有 `.env` 快照**时, `all()` 也必须体现运行时写入
     * @return void
     */
    public function testAllReflectsRuntimeWithoutEnvSnapshot(): void {
        $repo=new Repository($this->configs());
        $repo->put('database.connections.default.port',2222);
        $this->assertSame(2222,$repo->get('database.connections.default.port'));
        $this->assertSame(2222,$repo->all()['database']['connections']['default']['port'],'all() 与 get() 必须一致');
        $this->assertSame(2222,$repo->get('database.connections.default')['port'],'数组节点读法同样一致');
        $this->assertSame('demo',$repo->all()['app']['name'],'其余部分照旧');
    }

    /**
     * 测试: `all()` 也体现运行时写入, 且只写回已存在的路径
     * @return void
     */
    public function testAllIncludesRuntimeValues(): void {
        $repo=new Repository($this->configs(),$this->env('app.name=from-env'));
        $repo->put('app.name','from-runtime');
        $all=$repo->all();
        $this->assertSame('from-runtime',$all['app']['name']);
        $this->assertSame(3306,$all['database']['connections']['default']['port'],'未涉及项保持文件值');
    }

    /**
     * 测试: `env()` 读**本仓储那份 `.env` 层**的原值(与 `config_raw()` 对称)
     *
     * - 原值不折算: 同一个 `.env` 键, 覆盖后 `get()` 给 int、`env()` 仍给字符串
     * - 没带快照的仓储: `.env` 层视为空, 回落默认值(与 `get()` / `all()` 不套覆盖同一口径)
     *
     * @return void
     */
    public function testEnvReadsItsOwnLayer(): void {
        $repo=new Repository($this->configs(),$this->env('database.connections.default.port=13306'));
        $this->assertSame(13306,$repo->get('database.connections.default.port'),'生效值: 覆盖并按文件类型折算成 int');
        $this->assertSame('13306',$repo->env('database.connections.default.port'),'`.env` 层: 原样字符串');
        $this->assertSame('dflt',$repo->env('not.in.dot.env','dflt'),'该层里没有的键回落默认值');
        $repo_without_env=new Repository($this->configs());
        $this->assertSame('dflt',$repo_without_env->env('database.connections.default.port','dflt'),'没带快照: 该层为空');
    }

}
