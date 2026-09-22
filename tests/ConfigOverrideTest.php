<?php

namespace Tests;

use PHPUnit\Framework\TestCase;

use AdminService\Config\Env;
use AdminService\Config\Repository;

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
        $repo=new Repository($this->configs(),array(),$this->env(implode("\n",array(
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
        $repo=new Repository($this->configs(),array(),$this->env(implode("\n",array(
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
        $repo=new Repository($this->configs(),array(),$this->env('route.files=/nope'));
        $this->assertSame(array('/routes'),$repo->get('route.files'),'数组路径不会被字符串覆盖');
        $this->assertSame(array('/routes'),$repo->all()['route']['files']);
    }

    /**
     * 测试: `all()` 给的是**生效值**, 且只在已存在的路径上写回
     * @return void
     */
    public function testAllReturnsEffectiveValues(): void {
        $repo=new Repository($this->configs(),array(),$this->env('app.name=overridden'));
        $all=$repo->all();
        $this->assertSame('overridden',$all['app']['name']);
        $this->assertSame('localhost',$all['database']['connections']['default']['host']);
        $this->assertTrue(is_array($all['route']['files']),'未涉及的部分原样保留');
    }

    /**
     * 测试: `file()` 只看配置文件(与被覆盖后的 `get()` 对照)
     * @return void
     */
    public function testFileBypassesOverride(): void {
        $repo=new Repository($this->configs(),array(),$this->env('app.name=overridden'));
        $this->assertSame('overridden',$repo->get('app.name'));
        $this->assertSame('demo',$repo->file('app.name'),'`file()` 给的是配置文件里的原值');
        $this->assertSame('dflt',$repo->file('nope','dflt'));
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
     * 测试: 类型转不了就原样给出(不猜、不抛)
     * @return void
     */
    public function testUncastableOverrideIsReturnedAsIs(): void {
        $repo=new Repository($this->configs(),array(),$this->env('database.connections.default.port=not-a-number'));
        $this->assertSame('not-a-number',$repo->get('database.connections.default.port'),'文件里是 int 但值不是数字 → 原样给出');
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
        $repo=new Repository($this->configs(),array(),$this->env(implode("\n",array(
            'app.debug=null',
            'app.name=null',
            'database.connections.default.port=null',
        ))));
        $this->assertFalse($repo->get('app.debug'),'bool 项: null → false');
        $this->assertSame('demo',$repo->get('app.name'),'字符串项: 忽略覆盖, 保留文件值(不给空串)');
        $this->assertSame(3306,$repo->get('database.connections.default.port'),'int 项: 忽略覆盖, 保留文件值(不给 null/0)');
    }


    /**
     * 测试: **运行时临时值优先级最高** —— 盖得过 `.env` 的路径键, 也盖得过配置文件
     *
     * - 用途: 临时改一项而不必重写整份配置;而且**其它键的 `.env` 覆盖照旧生效**(只加一层, 不拆原有那层)
     *
     * @return void
     */
    public function testRuntimeValueWinsOverEverything(): void {
        $repo=new Repository($this->configs(),array(),$this->env(implode("\n",array(
            'app.name=from-env',
            'database.connections.default.port=13306',
            'database.connections.default.host=from-env-host',
            'database.connections.default.password=from-env-pwd',
        ))));
        $repo->put('app.name','from-runtime');
        $repo->put('database.connections.default.port',2222);
        $this->assertSame('from-runtime',$repo->get('app.name'),'临时值盖过 .env 的路径键');
        $this->assertSame(2222,$repo->get('database.connections.default.port'),'临时值原样给出(不做类型转换 —— 它是代码写的, 不是解析来的)');
        // 关键对照: 同一份仓储里, **没被临时值碰过**的键, `.env` 覆盖照旧生效(证明是"加一层", 不是拆了 `.env` 那层)
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
     * 测试: `putAll()`(供 `Config::set()` 用)把树里的标量叶子钉到临时层
     *
     * - 于是"刚 set 的值"赢过 `.env` 的路径键(否则会出现"我明明 set 了却被改掉")
     *
     * @return void
     */
    public function testPutAllPinsSetValues(): void {
        $repo=new Repository($this->configs(),array(),$this->env('app.name=from-env'));
        $this->assertSame('from-env',$repo->get('app.name'),'先确认 `.env` 覆盖是生效的');
        $repo->putAll(array('app'=>array('name'=>'from-set')));
        $this->assertSame('from-set',$repo->get('app.name'),'钉过之后 set 的值胜出');
        $this->assertSame('demo',$repo->file('app.name'),'`file()` 仍然只看配置文件, 不受影响');
    }

    /**
     * 测试: `all()` 也体现临时值, 且只写回已存在的路径
     * @return void
     */
    public function testAllIncludesRuntimeValues(): void {
        $repo=new Repository($this->configs(),array(),$this->env('app.name=from-env'));
        $repo->put('app.name','from-runtime');
        $all=$repo->all();
        $this->assertSame('from-runtime',$all['app']['name']);
        $this->assertSame(3306,$all['database']['connections']['default']['port'],'未涉及项保持文件值');
    }

}
