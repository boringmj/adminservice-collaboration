<?php

namespace Tests;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;

use AdminService\Cache;
use AdminService\Cache\CompileStore;
use AdminService\Config\Bootstrapper;
use AdminService\Config\Repository;
use AdminService\Container;
use AdminService\Exception;
use AdminService\Runtime;
use base\CacheInterface;
use base\ConfigInterface;

use function AdminService\env;
use function AdminService\env_reset;
use function AdminService\env_snapshot;
use function fclose;
use function file_put_contents;
use function fopen;
use function function_exists;
use function getenv;
use function is_dir;
use function is_file;
use function is_link;
use function mkdir;
use function opcache_invalidate;
use function putenv;
use function rmdir;
use function scandir;
use function sha1;
use function strcmp;
use function sys_get_temp_dir;
use function time;
use function touch;
use function uniqid;
use function usort;
use function var_export;

/**
 * 通用缓存测试
 *
 * - 覆盖读写、TTL、版本指纹、原子计数、分池、损坏自愈
 * - 覆盖自动 GC 与单次扫描上限
 * - 覆盖编译缓存清理与容器注入
 */
class CacheTest extends TestCase {

    /**
     * 临时目录
     * @var string
     */
    private string $dir;

    /**
     * 测试配置
     * @var Repository
     */
    private Repository $config;

    /**
     * 用例前的 Runtime 路径
     * @var string
     */
    private string $old_runtime;

    /**
     * 用例前的环境快照路径覆盖
     * @var string|false
     */
    private string|false $old_env_cache=false;

    /**
     * 每个用例前创建隔离目录
     *
     * @return void
     */
    protected function setUp(): void {
        $this->dir=sys_get_temp_dir().'/adminservice_cache_'.uniqid('',true);
        mkdir($this->dir,0755,true);
        $this->old_runtime=Runtime::path();
        Runtime::setPath($this->dir.'/runtime');
        // 清理编译缓存的用例不能删掉本地真实的环境快照
        $this->old_env_cache=getenv('ADMINSERVICE_TEST_ENV_CACHE');
        putenv('ADMINSERVICE_TEST_ENV_CACHE='.$this->dir.'/env.cache.php');
        $this->config=$this->makeConfig();
    }

    /**
     * 每个用例后清理临时目录
     *
     * @return void
     */
    protected function tearDown(): void {
        if($this->old_env_cache===false)
            putenv('ADMINSERVICE_TEST_ENV_CACHE');
        else
            putenv('ADMINSERVICE_TEST_ENV_CACHE='.$this->old_env_cache);
        Runtime::setPath($this->old_runtime);
        $this->removePath($this->dir);
    }

    /**
     * 构造缓存配置
     *
     * @param array<string,mixed> $overrides 覆盖项
     * @return Repository
     */
    private function makeConfig(array $overrides=array()): Repository {
        $cache=array_merge(array(
            'path'=>$this->dir.'/cache',
            'dir_mode'=>0755,
            'default_pool'=>'default',
            'default_ttl'=>60,
            'gc_probability'=>0,
            'gc_divisor'=>100,
            'gc_max_files'=>200,
            'compile'=>array(
                'enabled'=>true,
                'mode'=>'auto',
                'config'=>true,
                'route'=>true,
                'container'=>true
            )
        ),$overrides);
        return new Repository(array('cache'=>$cache));
    }

    /**
     * 递归删除临时目录(包含隐藏的临时文件与锁文件)
     *
     * @param string $path 文件或目录
     * @return void
     */
    private function removePath(string $path): void {
        if(is_link($path)||is_file($path)) {
            @unlink($path);
            return;
        }
        if(!is_dir($path))
            return;
        $entries=scandir($path);
        if($entries!==false) {
            foreach($entries as $entry) {
                if($entry==='.'||$entry==='..')
                    continue;
                $this->removePath($path.'/'.$entry);
            }
        }
        @rmdir($path);
    }

    /**
     * 取缓存条目的真实路径
     *
     * @param Cache $cache 缓存
     * @param string $key 键
     * @return string
     */
    private function entryPath(Cache $cache,string $key): string {
        $method=new ReflectionMethod(Cache::class,'entryPath');
        $method->setAccessible(true);
        return (string)$method->invoke($cache,$key);
    }

    /**
     * 强制把条目改成已过期
     *
     * @param Cache $cache 缓存
     * @param string $key 键
     * @return string 条目路径
     */
    private function expireEntry(Cache $cache,string $key): string {
        $path=$this->entryPath($cache,$key);
        $entry=include $path;
        $entry['expire']=time()-1;
        file_put_contents($path,"<?php\n\nreturn ".var_export($entry,true).";\n");
        if(function_exists('opcache_invalidate'))
            @opcache_invalidate($path,true);
        return $path;
    }

    /**
     * 测试: 基础读写、显式 null 与幂等删除
     *
     * @return void
     */
    public function testSetGetAndExplicitNull(): void {
        $cache=new Cache($this->config);

        $this->assertTrue($cache->set('name','demo'));
        $this->assertSame('demo',$cache->get('name'));

        $this->assertTrue($cache->set('nullable',null));
        $this->assertTrue($cache->has('nullable'));
        $this->assertNull($cache->get('nullable','fallback'));

        $this->assertFalse($cache->has('missing'));
        $this->assertSame('fallback',$cache->get('missing','fallback'));

        $this->assertTrue($cache->delete('nullable'));
        $this->assertFalse($cache->has('nullable'));
        $this->assertTrue($cache->delete('nullable'),'删除不存在的键应保持幂等');
    }

    /**
     * 测试: TTL 过期后读取会删除文件并返回默认值
     *
     * @return void
     */
    public function testTtlExpiresAndDeletes(): void {
        $cache=new Cache($this->config);
        $cache->set('expire','value',60);
        $path=$this->expireEntry($cache,'expire');

        $this->assertSame('fallback',$cache->get('expire','fallback'));
        $this->assertFileDoesNotExist($path);
    }

    /**
     * 测试: remember 命中不回调, 版本变化时重建
     *
     * @return void
     */
    public function testRememberAndVersionInvalidation(): void {
        $cache=new Cache($this->config);
        $calls=0;

        $first=$cache->remember('profile',60,function() use (&$calls): string {
            $calls++;
            return 'v1';
        },'v1');
        $second=$cache->remember('profile',60,function() use (&$calls): string {
            $calls++;
            return 'v1';
        },'v1');

        $this->assertSame('v1',$first);
        $this->assertSame('v1',$second);
        $this->assertSame(1,$calls,'命中时不应执行回调');

        $third=$cache->remember('profile',60,function() use (&$calls): string {
            $calls++;
            return 'v2';
        },'v2');
        $this->assertSame('v2',$third);
        $this->assertSame(2,$calls,'版本变化时应回源');
        $this->assertFalse($cache->has('profile','v1'));
        $this->assertTrue($cache->has('profile','v2'));
    }

    /**
     * 测试: 原子自增/自减
     *
     * @return void
     */
    public function testIncrementAndDecrement(): void {
        $cache=new Cache($this->config);

        $this->assertSame(1,$cache->increment('hits'));
        $this->assertSame(2,$cache->increment('hits'));
        $this->assertSame(1,$cache->decrement('hits'));
        $this->assertSame(1,$cache->get('hits'));

        $this->assertSame(5,$cache->increment('other',5));
        $this->assertSame(3,$cache->decrement('other',2));
    }

    /**
     * 测试: 超出整数范围的字符串不能参与计数
     *
     * @return void
     */
    public function testIncrementRejectsIntegerStringOverflow(): void {
        $cache=new Cache($this->config);
        $cache->set('counter','9999999999999999999999999999999999999');
        $this->expectException(Exception::class);
        $cache->decrement('counter');
    }

    /**
     * 测试: 非整数缓存不能自增
     *
     * @return void
     */
    public function testIncrementRejectsNonInteger(): void {
        $cache=new Cache($this->config);
        $cache->set('text','abc');

        $this->expectException(Exception::class);
        $cache->increment('text');
    }

    /**
     * 测试: 分池隔离, 清空一个池不影响另一个池
     *
     * @return void
     */
    public function testScopeIsolationAndClear(): void {
        $cache=new Cache($this->config);
        $auth=$cache->scope('auth');

        $cache->set('key','default');
        $auth->set('key','auth');

        $this->assertSame('default',$cache->get('key'));
        $this->assertSame('auth',$auth->get('key'));
        $this->assertSame('default',$cache->pool());
        $this->assertSame('auth',$auth->pool());

        $this->assertTrue($cache->clear());
        $this->assertFalse($cache->has('key'));
        $this->assertTrue($auth->has('key'));
    }

    /**
     * 测试: 损坏的缓存文件按未命中处理并自动删除
     *
     * @return void
     */
    public function testCorruptEntryIsDeleted(): void {
        $cache=new Cache($this->config);
        $cache->set('bad','value');
        $path=$this->entryPath($cache,'bad');
        file_put_contents($path,'<?php this is broken');
        if(function_exists('opcache_invalidate'))
            @opcache_invalidate($path,true);

        $this->assertSame('fallback',$cache->get('bad','fallback'));
        $this->assertFileDoesNotExist($path);

        file_put_contents($path,'<?php return array("not_key"=>1);');
        if(function_exists('opcache_invalidate'))
            @opcache_invalidate($path,true);
        $this->assertSame('fallback',$cache->get('bad','fallback'));
        $this->assertFileDoesNotExist($path);
    }

    /**
     * 测试: 对象走序列化路径且可完整读回
     *
     * @return void
     */
    public function testObjectIsSerializedAndRestored(): void {
        $cache=new Cache($this->config);
        $value=new CacheFixtureValue('demo',array('a'=>1));

        $this->assertTrue($cache->set('object',$value));
        $restored=$cache->get('object');

        $this->assertInstanceOf(CacheFixtureValue::class,$restored);
        $this->assertSame('demo',$restored->name);
        $this->assertSame(array('a'=>1),$restored->data);

        $entry=include $this->entryPath($cache,'object');
        $this->assertTrue($entry['serialized']);
    }

    /**
     * 测试: 无法序列化的值必须抛受控异常
     *
     * @return void
     */
    public function testUnsupportedValueThrows(): void {
        $cache=new Cache($this->config);

        $this->expectException(Exception::class);
        $cache->set('closure',function(): void {
        });
    }

    /**
     * 测试: 嵌套资源必须被拒绝, 不能静默序列化
     *
     * @return void
     */
    public function testNestedResourceThrows(): void {
        $cache=new Cache($this->config);
        $handle=fopen('php://memory','r');
        $this->expectException(Exception::class);
        try {
            $cache->set('resource',array('handle'=>$handle));
        } finally {
            fclose($handle);
        }
    }

    /**
     * 测试: 批量操作
     *
     * @return void
     */
    public function testMultipleOperations(): void {
        $cache=new Cache($this->config);

        $this->assertTrue($cache->setMultiple(array('a'=>1,'b'=>2)));
        $this->assertSame(array('a'=>1,'b'=>2,'c'=>'fallback'),$cache->getMultiple(array('a','b','c'),'fallback'));
        $this->assertTrue($cache->deleteMultiple(array('a','b')));
        $this->assertFalse($cache->has('a'));
        $this->assertFalse($cache->has('b'));
    }

    /**
     * 测试: pull 读取后立即删除
     *
     * @return void
     */
    public function testPullRemovesEntry(): void {
        $cache=new Cache($this->config);
        $cache->set('token','abc');

        $this->assertSame('abc',$cache->pull('token'));
        $this->assertFalse($cache->has('token'));
        $this->assertSame('fallback',$cache->pull('token','fallback'));
    }

    /**
     * 测试: 单次 GC 受文件数上限保护
     *
     * @return void
     */
    public function testGcRespectsMaxFiles(): void {
        $cache=new Cache($this->config);
        foreach(array('a','b','c') as $key) {
            $cache->set($key,$key);
            $this->expireEntry($cache,$key);
        }

        $this->assertSame(1,$cache->gc(1),'只允许检查一个文件');
        $this->assertSame(2,$cache->gc(0),'剩余两个过期文件应被清理');
    }

    /**
     * 测试: 有限额 GC 跨调用推进, 不被固定前缀阻塞
     *
     * @return void
     */
    public function testGcCursorAdvancesPastLivePrefix(): void {
        $cache=new Cache($this->config);
        $keys=array('first','second','third');
        usort($keys,fn(string $a,string $b): int => strcmp(sha1($a),sha1($b)));
        foreach($keys as $key)
            $cache->forever($key,$key);
        $expired=$this->expireEntry($cache,$keys[2]);

        for($i=0;$i<5;$i++)
            $cache->gc(2);
        $this->assertFileDoesNotExist($expired);
    }

    /**
     * 测试: GC 清理过期条目
     *
     * @return void
     */
    public function testGcRemovesExpiredEntries(): void {
        $cache=new Cache($this->config);
        $cache->set('expired','value');
        $cache->set('live','value');
        $expired_path=$this->expireEntry($cache,'expired');
        $live_path=$this->entryPath($cache,'live');

        $this->assertGreaterThanOrEqual(1,$cache->gc(0));
        $this->assertFileDoesNotExist($expired_path);
        $this->assertFileExists($live_path);
    }

    /**
     * 测试: 写入时按概率触发自动 GC
     *
     * @return void
     */
    public function testAutoGcRunsOnWrite(): void {
        $config=$this->makeConfig(array('gc_probability'=>1,'gc_divisor'=>1));
        $cache=new Cache($config);
        $cache->set('expired','value');
        $path=$this->expireEntry($cache,'expired');

        $cache->set('trigger','value');
        $this->assertFileDoesNotExist($path);
    }

    /**
     * 测试: 编译缓存清理与全量清理
     *
     * @return void
     */
    public function testClearCompileAndClearAll(): void {
        $cache=new Cache($this->config);
        $compile=$this->dir.'/cache/compile';
        mkdir($compile,0755,true);
        $config_file=$compile.'/config.cache.php';
        $route_file=$compile.'/routes.cache.php';
        file_put_contents($config_file,'<?php return array();');
        file_put_contents($route_file,'<?php return array();');

        $this->assertTrue($cache->clearCompile('config'));
        $this->assertFileDoesNotExist($config_file);
        $this->assertFileExists($route_file);

        $this->assertTrue($cache->clearCompile());
        $this->assertFileDoesNotExist($route_file);
        $this->assertTrue(is_dir($compile),'清空编译缓存后保留目录本身');

        $cache->set('key','value');
        $this->assertTrue($cache->clearAll());
        $this->assertTrue(is_dir($this->dir.'/cache'),'全量清理后保留缓存根目录');
        $this->assertFalse(is_dir($this->dir.'/cache/kv'));
        $this->assertFalse(is_dir($this->dir.'/cache/compile'));
    }

    /**
     * 测试: 非法键与非法池名必须被拒绝
     *
     * @return void
     */
    /**
     * 测试: `.env` 的字符串 `0` 与布尔 false 一样关闭编译缓存
     *
     * @return void
     */
    public function testCompileStoreTreatsStringZeroAsDisabled(): void {
        $config=$this->makeConfig(array(
            'compile'=>array(
                'enabled'=>'0',
                'mode'=>'auto',
                'config'=>true,
                'route'=>true,
                'container'=>true
            )
        ));
        $store=new CompileStore($config);

        $this->assertSame('off',$store->mode());
        $this->assertFalse($store->enabled('route'));
        $this->assertFalse($store->enabled('container'));
    }

    /**
     * 测试: 清空接口兼容 `route` 别名
     *
     * @return void
     */
    public function testClearCompileAcceptsRouteAlias(): void {
        $cache=new Cache($this->config);
        $compile=$this->dir.'/cache/compile';
        mkdir($compile,0755,true);
        $route_file=$compile.'/routes.cache.php';
        file_put_contents($route_file,'<?php return array();');

        $this->assertTrue($cache->clearCompile('route'));
        $this->assertFileDoesNotExist($route_file);
    }

    public function testGcRemovesStaleAuxiliaryFiles(): void {
        $cache=new Cache($this->config);
        $pool=$this->dir.'/cache/kv/default';
        mkdir($pool,0755,true);
        $lock=$pool.'/key.lock';
        $tmp=$pool.'/.cachetmp_stale';
        file_put_contents($lock,'');
        file_put_contents($tmp,'');
        touch($lock,time()-7200);
        touch($tmp,time()-7200);

        $this->assertGreaterThanOrEqual(1,$cache->gc(0));
        $this->assertFileExists($lock,'锁文件不能被 GC 删除');
        $this->assertFileDoesNotExist($tmp);
    }

    /**
     * 测试: 清空池时保留锁文件
     *
     * @return void
     */
    public function testClearPreservesLockFiles(): void {
        $cache=new Cache($this->config);
        $cache->increment('counter');
        $lock=$this->entryPath($cache,'counter').'.lock';
        $this->assertFileExists($lock);

        $this->assertTrue($cache->clear());
        $this->assertFileExists($lock,'清空池不能删除并发锁文件');
        $this->assertNull($cache->get('counter'));
    }

    /**
     * 测试: 全量清空也保留锁文件
     *
     * @return void
     */
    public function testClearAllPreservesLockFiles(): void {
        $cache=new Cache($this->config);
        $cache->increment('counter');
        $lock=$this->entryPath($cache,'counter').'.lock';
        $this->assertFileExists($lock);

        $this->assertTrue($cache->clearAll());
        $this->assertFileExists($lock,'全量清空不能删除并发锁文件');
        $this->assertNull($cache->get('counter'));
    }

    /**
     * 测试: 清理环境快照后进程快照回到实时读取
     *
     * @return void
     */
    public function testClearEnvCacheResetsProcessSnapshot(): void {
        $previous_env=getenv('ADMINSERVICE_TEST_ENV_FILE');
        $env_file=$this->dir.'/env-src';
        file_put_contents($env_file,"KEY=live\n");
        putenv('ADMINSERVICE_TEST_ENV_FILE='.$env_file);
        try {
            env_reset();
            (new Bootstrapper())->buildEnvCache();
            $this->assertTrue(env_snapshot()->compiled());

            $cache=new Cache($this->config);
            $this->assertTrue($cache->clearCompile('env'));
            $this->assertFalse(env_snapshot()->compiled(),'清理环境快照后应回到实时读取');
            $this->assertSame('live',env('KEY'));
        } finally {
            if($previous_env===false)
                putenv('ADMINSERVICE_TEST_ENV_FILE');
            else
                putenv('ADMINSERVICE_TEST_ENV_FILE='.$previous_env);
            env_reset();
        }
    }

    public function testInvalidKeyAndPoolAreRejected(): void {
        $cache=new Cache($this->config);

        try {
            $cache->get('');
            $this->fail('空键应抛异常');
        } catch(Exception) {
        }

        $this->expectException(Exception::class);
        $cache->scope('bad pool');
    }

    /**
     * 测试: 容器可按契约注入缓存实现
     *
     * @return void
     */
    public function testContainerCanResolveCacheContract(): void {
        $container=new Container();
        $container->instance(Repository::class,$this->config);
        $container->alias(ConfigInterface::class,Repository::class);
        $container->alias(CacheInterface::class,Cache::class);

        $cache=$container->get(CacheInterface::class);
        $this->assertInstanceOf(Cache::class,$cache);
        $this->assertSame('default',$cache->pool());
    }

}

/**
 * 缓存对象序列化测试值
 */
final class CacheFixtureValue {

    /**
     * 构造方法
     *
     * @param string $name 名称
     * @param array<string,mixed> $data 数据
     */
    public function __construct(
        public string $name,
        public array $data=array()
    ) {
    }

}
