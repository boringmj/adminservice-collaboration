<?php

namespace Tests;

use PHPUnit\Framework\TestCase;

use AdminService\ClassFinder;
use AdminService\ReflectionCache;
use Tests\Fixtures\ClassFinderCacheInterface;
use Tests\Fixtures\ClassFinderCacheLazyInterface;

use function is_a;

/**
 * `ClassFinder` 子类查找缓存的用例
 *
 * 为什么要有它: `findDirectSubClassRecursive` 每次都 `get_declared_classes()` 取全量已声明类, 再对
 * **每个**类做 `get_parent_class()` / `class_implements(...,true)`(每个都是 O(接口数)), 且原本没有任何缓存。
 * 实测它**单请求 0 次调用**(评估 C7), 所以这条是**规模化防御**(代价随已声明类数增长), 不是性能收益 ——
 * 用例要证明的是"第二次不再扫", 而不是"快了多少"。
 *
 * ⚠ 另外把一条**既有脆弱性**写在这里(不是本次引入的): 查找只在**已声明类**里进行, 所以某个子类的文件
 * 还没被加载时找不到它 —— 也就是说结果取决于"进程里恰好加载了哪些类"。实测复现:
 * `vendor/bin/phpunit --filter 'AppTest|ContainerBindingTest|ScopeTest|AutowireCycleTest'`
 * 会因为 `Tests\Fixtures\UserStatus` 没被顺带加载而报 `AbstractStatus is not instantiable`
 * (改动前的代码同样失败)。缓存为此带"已声明类数"版本号, 保证新声明了类之后一定重扫。
 */
class ClassFinderCacheTest extends TestCase {

    /**
     * 类初始化前执行
     * @return void
     */
    public static function setUpBeforeClass(): void {
        // 同文件里声明的接口与实现一起加载, 查找结果因此不依赖用例顺序
        require_once __DIR__.'/fixtures/ClassFinderCacheFixture.php';
    }

    /**
     * 断言查到的确实是该接口的某个实现(不断言具体是哪一个 —— 声明顺序不该影响结论)
     *
     * @param string|null $found 查找结果
     * @return void
     */
    private function assertIsImplementation(?string $found): void {
        $this->assertNotNull($found,'应当能找到一个可实例化的实现类');
        $this->assertTrue(is_a($found,ClassFinderCacheInterface::class,true));
    }

    /**
     * 测试: 第二次查询命中缓存, 不再全量扫描
     * @return void
     */
    public function testSecondLookupDoesNotScanAgain(): void {
        $finder=new ClassFinder();
        $this->assertIsImplementation($finder->findDirectSubClassRecursive(ClassFinderCacheInterface::class));
        $scans=$finder->scanCount();
        $this->assertGreaterThan(0,$scans,'第一次必须真的扫过(否则这条用例是空的)');

        $this->assertIsImplementation($finder->findDirectSubClassRecursive(ClassFinderCacheInterface::class));
        $this->assertSame($scans,$finder->scanCount(),'第二次必须命中缓存, 扫描次数不应增加');
    }

    /**
     * 测试: **否定结果不许被缓存** —— 后来才声明的实现类必须能立刻找到
     *
     * - 这条是缓存设计里最重要的一条: 查找只在已声明类里扫, "此刻没找到"随时可能变成"找得到"
     *   (某个子类刚被加载)。把否定结果缓存住, 就会把临时答案固化成错答案, 而"先判过、后来才加载到"
     *   这种顺序在真实运行里完全可能出现
     * - 曾经试过"用已声明类数当版本号"来失效: **太敏感** —— 实测两次调用之间光是懒加载就让声明类数
     *   从 470 跳到 478, 缓存直接全废。这个结论就是被这条用例逼出来的
     *
     * @return void
     */
    public function testNegativeResultIsNotFrozen(): void {
        $finder=new ClassFinder();
        $this->assertNull($finder->findDirectSubClassRecursive(ClassFinderCacheLazyInterface::class),'此刻还没有实现类');

        eval('namespace Tests\Fixtures; class ClassFinderCacheLazyImpl implements \Tests\Fixtures\ClassFinderCacheLazyInterface {}');

        $this->assertSame(
            'Tests\Fixtures\ClassFinderCacheLazyImpl',
            $finder->findDirectSubClassRecursive(ClassFinderCacheLazyInterface::class),
            '新声明的实现必须能被找到(否定结果没有被缓存)'
        );
    }

    /**
     * 测试: 共用同一份反射缓存的两个查找器共享结果(请求级容器与父容器就是这个用法)
     * @return void
     */
    public function testCacheIsSharedThroughReflectionCache(): void {
        $reflections=new ReflectionCache();
        $first=new ClassFinder($reflections);
        $second=new ClassFinder($reflections);
        $first->findDirectSubClassRecursive(ClassFinderCacheInterface::class);

        $before=$second->scanCount();
        $this->assertIsImplementation($second->findDirectSubClassRecursive(ClassFinderCacheInterface::class));
        $this->assertSame($before,$second->scanCount(),'共享反射缓存时同一个查询不该扫第二遍');
    }

    /**
     * 测试: 名字根本不存在时连扫描都不会发生(正因为如此, 缓存与否无关紧要)
     * @return void
     */
    public function testUnknownClassDoesNotScan(): void {
        $finder=new ClassFinder();
        $this->assertNull($finder->findDirectSubClassRecursive('Tests\Fixtures\NoSuchClassFinderTarget'));
        $this->assertSame(0,$finder->scanCount(),'类都不存在, 谈不上扫描');
    }

}
