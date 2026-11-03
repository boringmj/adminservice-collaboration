<?php

namespace Tests;

use PHPUnit\Framework\TestCase;

use AdminService\Exception;
use AdminService\Router\Router;
use ReflectionProperty;
use Tests\Fixtures\ConcreteService;

/**
 * 路由缓存懒加载测试
 *
 * - 缓存导入不立即构造全部 RouteItem
 * - find() 只水合命中的路由
 * - getRoutes()/export()/url()/405 保持原行为
 */
class RouterLazyImportTest extends TestCase {

    /**
     * 构造包含静态、动态和命名路由的路由表
     *
     * @return array<array<string,mixed>>
     */
    private function exportedRoutes(): array {
        $router=new Router();
        $router->get('/static',array(ConcreteService::class,'execute'))->name('static');
        $router->get('/user/{id}',array(ConcreteService::class,'execute'))->name('show');
        $router->post('/user',array(ConcreteService::class,'execute'))->name('store');
        return $router->export();
    }

    /**
     * 读取私有属性
     *
     * @param object $object 对象
     * @param string $name 属性名
     * @return mixed
     */
    private function property(object $object,string $name): mixed {
        $property=new ReflectionProperty($object,$name);
        $property->setAccessible(true);
        return $property->getValue($object);
    }

    /**
     * 测试: 懒加载导入后只水合命中路由
     *
     * @return void
     */
    public function testImportDefersRouteConstruction(): void {
        $router=new Router();
        $router->import($this->exportedRoutes(),true);

        $this->assertCount(0,$this->property($router,'routes'));
        $this->assertCount(3,$this->property($router,'route_data'));
        $this->assertTrue($this->property($router,'lazy'));

        $found=$router->find('GET','/static');
        $this->assertNotNull($found);
        $this->assertSame(array(ConcreteService::class,'execute'),$found[0]->getHandler());
        $this->assertCount(0,$this->property($router,'routes'),'find 只水合命中路由, 不应填满 routes');

        $found=$router->find('GET','/user/42');
        $this->assertSame(array('id'=>'42'),$found[1]);
        $this->assertSame(array(ConcreteService::class,'execute'),$found[0]->getHandler());
    }

    /**
     * 测试: 405/命名路由/导出在懒加载下保持行为
     *
     * @return void
     */
    public function testLazyMetadataOperations(): void {
        $routes=$this->exportedRoutes();
        $router=new Router();
        $router->import($routes,true);

        $this->assertSame(array('GET','HEAD'),$router->allowedMethods('/static'));
        $this->assertSame(array('POST'),$router->allowedMethods('/user'));
        $this->assertSame(array(),$router->allowedMethods('/missing'));

        $this->assertSame('/user/42',$router->url('show',array('id'=>42)));
        $this->assertSame('/static',$router->url('static'));
        $this->assertTrue($router->has('store'));
        $this->assertFalse($router->has('missing'));

        $this->assertSame($routes,$router->export(),'懒加载导出应与原始导出一致');
    }

    /**
     * 测试: getRoutes 才水合全部, 并恢复普通路径
     *
     * @return void
     */
    public function testGetRoutesHydratesAll(): void {
        $router=new Router();
        $router->import($this->exportedRoutes(),true);

        $this->assertCount(3,$router->getRoutes());
        $this->assertFalse($this->property($router,'lazy'));
        $this->assertCount(0,$this->property($router,'route_data'));
        $this->assertSame(array(ConcreteService::class,'execute'),$router->find('GET','/user/7')[0]->getHandler());
    }

    /**
     * 测试: 未排序导入仍按具体度匹配
     *
     * @return void
     */
    public function testUnsortedImportStillMatchesSpecificRouteFirst(): void {
        $routes=$this->exportedRoutes();
        $router=new Router();
        $router->import(array_reverse($routes),false);

        $this->assertSame(array(ConcreteService::class,'execute'),$router->find('GET','/static')[0]->getHandler());
        $this->assertSame(array(ConcreteService::class,'execute'),$router->find('GET','/user/7')[0]->getHandler());
        $this->assertSame($routes,$router->export());
    }

    /**
     * 测试: 显式关闭懒加载时保持旧的全量导入路径
     *
     * @return void
     */
    public function testEagerImportFallback(): void {
        $router=new Router();
        $router->import($this->exportedRoutes(),true,false);

        $this->assertCount(3,$this->property($router,'routes'));
        $this->assertFalse($this->property($router,'lazy'));
        $this->assertSame(array(ConcreteService::class,'execute'),$router->find('GET','/static')[0]->getHandler());
    }

    /**
     * 测试: 懒加载导入与 eager 导入使用相同的路径/方法规范化
     *
     * @return void
     */
    public function testLazyImportNormalizesRouteData(): void {
        $router=new Router();
        $router->get('/normalized',array(ConcreteService::class,'execute'));
        $routes=$router->export();
        $routes[0]['methods']=array('get');
        $routes[0]['path']='/normalized/';

        $eager=new Router();
        $eager->import($routes,true,false);
        $lazy=new Router();
        $lazy->import($routes,true);

        $this->assertNotNull($eager->find('GET','/normalized'));
        $this->assertNotNull($lazy->find('GET','/normalized'));
    }

    /**
     * 测试: 懒加载导入拒绝非法的 methods/params 类型
     *
     * @return void
     */
    public function testLazyImportRejectsInvalidMethods(): void {
        $routes=$this->exportedRoutes();
        $routes[0]['methods']='GET';

        $this->expectException(Exception::class);
        (new Router())->import($routes,true);
    }

    /**
     * 测试: 懒加载导入拒绝非法的 params 类型
     *
     * @return void
     */
    public function testLazyImportRejectsInvalidParams(): void {
        $routes=$this->exportedRoutes();
        $routes[0]['params']='id';

        $this->expectException(Exception::class);
        (new Router())->import($routes,true);
    }

    /**
     * 测试: 空路由名在 eager/lazy 导入下行为一致
     *
     * @return void
     */
    public function testLazyImportKeepsEmptyRouteNameConsistent(): void {
        $router=new Router();
        $router->get('/empty-name',array(ConcreteService::class,'execute'))->name('');
        $routes=$router->export();

        $eager=new Router();
        $eager->import($routes,true,false);
        $lazy=new Router();
        $lazy->import($routes,true);

        $this->assertTrue($eager->has(''));
        $this->assertSame($eager->has(''),$lazy->has(''));
        $this->assertSame($eager->url(''),$lazy->url(''));
    }

    /**
     * 测试: 未排序导入时静态路由优先于参数路由
     *
     * @return void
     */
    public function testUnsortedImportMatchesLiteralBeforeParameter(): void {
        $router=new Router();
        $router->get('/user/{id}',array(ConcreteService::class,'execute'))->name('show');
        $router->get('/user/create',array(ConcreteService::class,'execute'))->name('create');
        $routes=$router->export();

        $router=new Router();
        $router->import(array_reverse($routes),false);

        $this->assertSame('/user/create',$router->find('GET','/user/create')[0]->getPath());
        $this->assertSame(array('id'=>'42'),$router->find('GET','/user/42')[1]);
    }

    /**
     * 测试: 懒加载导入后继续注册路由会先水合已有数据
     *
     * @return void
     */
    public function testAddAfterLazyImportHydratesExistingRoutes(): void {
        $router=new Router();
        $router->import($this->exportedRoutes(),true);
        $router->get('/added',array(ConcreteService::class,'execute'))->name('added');

        $this->assertFalse($this->property($router,'lazy'));
        $this->assertCount(4,$router->getRoutes());
        $this->assertNotNull($router->find('GET','/static'));
        $this->assertNotNull($router->find('GET','/added'));
    }
}
