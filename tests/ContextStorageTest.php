<?php

namespace Tests;

use PHPUnit\Framework\TestCase;

use AdminService\App;
use AdminService\ArraySession;
use AdminService\Config\Repository;
use AdminService\ContextStorage;
use AdminService\CoroutineContextStorage;
use AdminService\FiberContextStorage;
use AdminService\HttpRequest;
use AdminService\RequestContext;
use AdminService\Response;
use AdminService\Router\Router;
use AdminService\SequentialContextStorage;
use AdminService\Container;
use base\Container as ContainerContract;
use Fiber;
use Tests\Fixtures\ConcreteService;

/**
 * 请求上下文存储测试
 *
 * - 顺序栈支持嵌套
 * - Fiber 上下文互不串用
 * - App 门面优先读取当前上下文容器
 */
class ContextStorageTest extends TestCase {

    /**
     * 测试前的容器
     * @var ContainerContract|null
     */
    private ?ContainerContract $old_container=null;

    /**
     * 保存测试环境
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();
        $this->old_container=App::hasInstance()?App::getInstance():null;
    }

    /**
     * 每个用例后恢复默认存储
     *
     * @return void
     */
    protected function tearDown(): void {
        ContextStorage::reset();
        if($this->old_container!==null)
            App::setInstance($this->old_container);
        parent::tearDown();
    }

    /**
     * 构造测试用请求上下文
     *
     * @param Container $container 容器
     * @return RequestContext
     */
    private function context(Container $container): RequestContext {
        $config=new Repository(array());
        return new RequestContext(
            $container,
            new HttpRequest(array()),
            new Response($config),
            new ArraySession(),
            $config
        );
    }

    /**
     * 测试: 顺序上下文支持嵌套并恢复
     *
     * @return void
     */
    public function testSequentialContextSupportsNesting(): void {
        $storage=new SequentialContextStorage();
        $a=$this->context(new Container());
        $b=$this->context(new Container());

        $this->assertNull($storage->current());
        $storage->run($a,function() use($storage,$a,$b): void {
            $this->assertSame($a,$storage->current());
            $storage->run($b,function() use($storage,$b): void {
                $this->assertSame($b,$storage->current());
            });
            $this->assertSame($a,$storage->current());
        });
        $this->assertNull($storage->current());
    }

    /**
     * 测试: 无协程扩展时协程存储回落到顺序/Fiber 存储
     *
     * @return void
     */
    public function testCoroutineStorageFallsBackWithoutExtension(): void {
        $storage=new CoroutineContextStorage();
        $context=$this->context(new Container());
        $storage->run($context,function() use ($storage,$context): void {
            $this->assertSame($context,$storage->current());
        });
        $this->assertNull($storage->current());
    }

    /**
     * 测试: Fiber 交错执行时上下文互不串用
     *
     * @return void
     */
    public function testFiberContextsAreIsolated(): void {
        $storage=new FiberContextStorage();
        $a=$this->context(new Container());
        $b=$this->context(new Container());
        $observed=array();

        $fiber_a=new Fiber(function() use($storage,$a,&$observed): void {
            $storage->run($a,function() use($storage,$a,&$observed): void {
                Fiber::suspend();
                $observed['a']=$storage->current();
                $this->assertSame($a,$storage->current());
            });
        });
        $fiber_b=new Fiber(function() use($storage,$b,&$observed): void {
            $storage->run($b,function() use($storage,$b,&$observed): void {
                Fiber::suspend();
                $observed['b']=$storage->current();
                $this->assertSame($b,$storage->current());
            });
        });

        $fiber_a->start();
        $fiber_b->start();
        $fiber_a->resume();
        $fiber_b->resume();

        $this->assertSame($a,$observed['a']);
        $this->assertSame($b,$observed['b']);
        $this->assertNull($storage->current());
    }

    /**
     * 测试: Router 最近命中路由按执行流隔离
     *
     * @return void
     */
    public function testRouterCurrentIsIsolatedByContext(): void {
        $router=new Router();
        $router->get('/a',array('C','a'))->name('a');
        $router->get('/b',array('C','b'))->name('b');
        $context_a=$this->context(new Container());
        $context_b=$this->context(new Container());

        $fiber_a=new Fiber(function() use ($router,$context_a): void {
            ContextStorage::run($context_a,function() use ($router): void {
                $router->find('GET','/a');
                Fiber::suspend();
                $this->assertSame('a',$router->current()?->getName());
                $router->resetCurrent();
                $this->assertNull($router->current());
            });
        });
        $fiber_b=new Fiber(function() use ($router,$context_b): void {
            ContextStorage::run($context_b,function() use ($router): void {
                $router->find('GET','/b');
                Fiber::suspend();
                $this->assertSame('b',$router->current()?->getName());
                $router->resetCurrent();
                $this->assertNull($router->current());
            });
        });

        $fiber_a->start();
        $fiber_b->start();
        $fiber_a->resume();
        $fiber_b->resume();
        $this->assertNull($router->current());
    }

    /**
     * 测试: App 门面优先从当前上下文容器取对象
     *
     * @return void
     */
    public function testAppFacadeUsesCurrentContext(): void {
        $application_container=new Container();
        $context_container=new Container();
        $fallback=new ConcreteService();
        $scoped=new ConcreteService();
        $application_container->instance(ConcreteService::class,$fallback);
        $context_container->instance(ConcreteService::class,$scoped);
        App::setInstance($application_container);
        ContextStorage::setStorage(new SequentialContextStorage());

        $this->assertSame($fallback,App::get(ConcreteService::class));
        ContextStorage::run($this->context($context_container),function() use($scoped): void {
            $this->assertSame($scoped,App::get(ConcreteService::class));
        });
        $this->assertSame($fallback,App::get(ConcreteService::class));
    }

}
