<?php

namespace Tests;

use PHPUnit\Framework\TestCase;

use AdminService\Exception;
use AdminService\Router\RouteItem;
use AdminService\Router\Router;
use ReflectionProperty;
use Tests\Fixtures\ConcreteService;

use function file_put_contents;
use function is_file;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

/**
 * 路由缓存懒加载测试
 *
 * - 缓存导入不立即构造全部 RouteItem
 * - find() 只实例化命中的路由
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
     * eager 导出的固定键
     *
     * @return array<int,string>
     */
    private function exportKeys(): array {
        return array(
            'methods',
            'path',
            'handler',
            'group_middlewares',
            'route_middlewares',
            'name',
            'bindings',
            'resolved_bindings',
            'pattern',
            'params',
            'optional',
            'constraints',
            'literal_length',
            'produces',
            'controller_middlewares'
        );
    }

    /**
     * 测试: 导入整张路由表时清空已加载文件记录
     *
     * @return void
     */
    public function testImportClearsLoadedFiles(): void {
        $file=tempnam(sys_get_temp_dir(),'route_loaded_');
        file_put_contents($file,"<?php\n\nreturn function(\\AdminService\\Router\\Router \$router): void {\n"
            ."    \$router->get('/loaded',static fn(): string => 'x');\n};\n");
        try {
            $router=new Router();
            $router->load($file);
            $this->assertNotSame(array(),$router->getLoadedFiles());
            $router->import(array(),true,true);
            $this->assertSame(array(),$router->getLoadedFiles());
        } finally {
            if(is_file($file))
                unlink($file);
        }
    }

    /**
     * 测试: 缺省 params 时从路径推导, 含参路径仍可命中
     *
     * @return void
     */
    public function testImportDerivesMissingParams(): void {
        foreach(array(true,false) as $lazy) {
            $router=new Router();
            $router->import(array(array(
                'path'=>'/user/{id}',
                'handler'=>array(ConcreteService::class,'execute'),
                'pattern'=>'#^/user/(?P<id>[^/]+)$#'
            )),true,$lazy);
            $found=$router->find('GET','/user/42');
            $this->assertNotNull($found);
            $this->assertSame(array('id'=>'42'),$found[1]);
        }
    }

    /**
     * 测试: 导入失败时保留原有路由表
     *
     * @return void
     */
    public function testImportFailureKeepsPreviousRoutes(): void {
        $router=new Router();
        $router->get('/old',static fn(): string => 'old');
        try {
            $router->import(array(
                array('path'=>'/a','handler'=>static fn(): string => 'a','pattern'=>'#^/a$#'),
                array('bogus'=>1)
            ),true,true);
            $this->fail('非法路由数据应抛出异常');
        } catch(Exception) {
        }
        $found=$router->find('GET','/old');
        $this->assertNotNull($found);
        $this->assertSame('/old',$found[0]->getPath());
    }

    /**
     * 测试: 懒加载导入后只实例化命中路由
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
        $this->assertCount(0,$this->property($router,'routes'),'find 只实例化命中路由, 不应填满 routes');

        $found=$router->find('GET','/user/42');
        $this->assertSame(array('id'=>'42'),$found[1]);
        $this->assertSame(array(ConcreteService::class,'execute'),$found[0]->getHandler());
    }

    /**
     * 测试: 懒加载静态路由也接受百分号编码
     *
     * @return void
     */
    public function testLazyStaticEncodedPath(): void {
        $router=new Router();
        $router->get('/hello world',array(ConcreteService::class,'execute'));
        $lazy=new Router();
        $lazy->import($router->export(),true);

        $found=$lazy->find('GET','/hello%20world');
        $this->assertNotNull($found);
        $this->assertSame('/hello world',$found[0]->getPath());
        $this->assertSame(array('GET','HEAD'),$lazy->allowedMethods('/hello%20world'));
    }

    /**
     * 测试懒加载下动态参数按命中的候选解码一次后返回
     *
     * @return void
     */
    public function testLazyDynamicParamsAreDecodedOnceByMatchedCandidate(): void {
        $router=new Router();
        $router->get('/hello world/{name}',array(ConcreteService::class,'execute'));
        $router->get('/raw/{name}',array(ConcreteService::class,'execute'));
        $lazy=new Router();
        $lazy->import($router->export(),true);

        $decoded=$lazy->find('GET','/hello%20world/a%2520b');
        $this->assertSame(array('name'=>'a%20b'),$decoded[1]);
        $raw=$lazy->find('GET','/raw/a%2520b');
        $this->assertSame(array('name'=>'a%20b'),$raw[1]);
    }

    /**
     * 测试: 懒加载下自定义约束的反向生成结果可被自身路由命中
     *
     * @return void
     */
    public function testLazyBuildUriRoundTripsCustomConstraint(): void {
        $router=new Router();
        $router->get('/tag/{name:[a-z+]+}',array(ConcreteService::class,'execute'))->name('tag');
        $lazy=new Router();
        $lazy->import($router->export(),true);

        $url=$lazy->url('tag',array('name'=>'c++'));
        $this->assertSame('/tag/c%2B%2B',$url);

        $found=$lazy->find('GET',$url);
        $this->assertNotNull($found);
        $this->assertSame(array('name'=>'c++'),$found[1]);
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

        $exported=$router->export();
        $this->assertSame($routes,$exported,'懒加载导出应与原始导出一致');
        $this->assertSame($this->exportKeys(),array_keys($exported[0]));
    }

    /**
     * 测试: 懒加载导出保留最小数据并补齐匹配所需键, 不实例化路由
     *
     * @return void
     */
    public function testLazyExportKeepsMinimalDataWithoutInstantiating(): void {
        $minimal=array(
            'path'=>'/minimal',
            'handler'=>array(ConcreteService::class,'execute'),
            'pattern'=>'#^/minimal$#'
        );
        $router=new Router();
        $router->import(array($minimal),true);

        $this->assertSame(array(array(
            'path'=>'/minimal',
            'handler'=>array(ConcreteService::class,'execute'),
            'pattern'=>'#^/minimal$#',
            'methods'=>array(),
            'params'=>array(),
            'optional'=>array(),
            'literal_length'=>8
        )),$router->export());
        $this->assertCount(0,$this->property($router,'routes'));
        $this->assertTrue($this->property($router,'lazy'));
    }

    /**
     * 测试: eager 导出使用固定键集合
     *
     * @return void
     */
    public function testEagerExportUsesCanonicalKeys(): void {
        $router=new Router();
        $router->get('/canonical',array(ConcreteService::class,'execute'))->name('canonical');

        $this->assertSame($this->exportKeys(),array_keys($router->export()[0]));
    }

    /**
     * 测试: getRoutes 才实例化全部, 并恢复普通路径
     *
     * @return void
     */
    public function testGetRoutesInstantiatesAll(): void {
        $router=new Router();
        $router->import($this->exportedRoutes(),true);

        $this->assertCount(3,$router->getRoutes());
        $this->assertFalse($this->property($router,'lazy'));
        $this->assertCount(0,$this->property($router,'route_data'));
        $this->assertSame(array(ConcreteService::class,'execute'),$router->find('GET','/user/7')[0]->getHandler());
        $this->assertSame($this->exportKeys(),array_keys($router->export()[0]));
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
     * 测试: 编译路由异常携带字段与路由
     *
     * @return void
     */
    public function testLazyImportErrorCarriesField(): void {
        $routes=$this->exportedRoutes();
        $routes[0]['params']='id';
        try {
            (new Router())->import($routes,true);
            $this->fail('非法 params 应被拒绝');
        } catch(Exception $e) {
            $data=$e->getData();
            $this->assertSame('params',$data['field']);
            $this->assertSame($routes[0]['path'],$data['route']);
        }
    }

    /**
     * 测试: 通配符编译失败也携带字段
     *
     * @return void
     */
    public function testCompiledRouteWildcardErrorCarriesField(): void {
        try {
            RouteItem::normalizeData(array(
                'path'=>'/a/*/b',
                'pattern'=>'/a/*/b',
                'handler'=>'demo'
            ));
            $this->fail('非法通配符应被拒绝');
        } catch(Exception $e) {
            $data=$e->getData();
            $this->assertSame('path',$data['field']);
            $this->assertSame('/a/*/b',$data['route']);
            $this->assertNotNull($e->getPrevious());
        }
    }

    /**
     * 测试: eager/lazy 导入都拒绝空路由名
     *
     * @return void
     */
    public function testImportRejectsEmptyRouteNameInBothModes(): void {
        $routes=$this->exportedRoutes();
        $routes[0]['name']='';

        foreach(array(false,true) as $lazy) {
            try {
                (new Router())->import($routes,true,$lazy);
                $this->fail('空路由名应被拒绝');
            } catch(Exception $e) {
                $this->assertSame('Invalid compiled route item.',$e->getMessage());
            }
        }
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
     * 测试: 懒加载导入后继续注册路由会先实例化已有数据
     *
     * @return void
     */
    public function testAddAfterLazyImportInstantiatesExistingRoutes(): void {
        $router=new Router();
        $router->import($this->exportedRoutes(),true);
        $router->get('/added',array(ConcreteService::class,'execute'))->name('added');

        $this->assertFalse($this->property($router,'lazy'));
        $this->assertCount(4,$router->getRoutes());
        $this->assertNotNull($router->find('GET','/static'));
        $this->assertNotNull($router->find('GET','/added'));
    }

    /**
     * 测试: 重复路由名在 eager/lazy 导入下都被拒绝
     *
     * @return void
     */
    public function testDuplicateRouteNamesRejectedInBothModes(): void {
        $routes=$this->exportedRoutes();
        $routes[1]['name']=$routes[0]['name'];

        foreach(array(false,true) as $lazy) {
            try {
                (new Router())->import($routes,true,$lazy);
                $this->fail('重复路由名应被拒绝');
            } catch(Exception $e) {
                $this->assertSame('Duplicate route name.',$e->getMessage());
            }
        }
    }

    /**
     * 测试: 懒加载下首段桶匹配保持全局具体度排序
     *
     * - `/{module}/detail/{id}` 字面量长于 `/user/{any?:.*}`, 同命中时取通配路由
     * - `/user/detail/{id}` 字面量长于 `/{a}/{b}/{c}`, 同命中时取桶内路由
     *
     * @return void
     */
    public function testLazyBucketsKeepGlobalSpecificityOrder(): void {
        $first_source=new Router();
        $first_source->get('/user/*',array(ConcreteService::class,'execute'))->name('wildcard_bucket');
        $first_source->get('/{module}/detail/{id}',array(ConcreteService::class,'execute'))->name('catchall');
        $first=new Router();
        $first->import($first_source->export(),true,true);
        $this->assertSame('catchall',$first->find('GET','/user/detail/9')[0]->getName());

        $second_source=new Router();
        $second_source->get('/{a}/{b}/{c}',array(ConcreteService::class,'execute'))->name('three_params');
        $second_source->get('/user/detail/{id}',array(ConcreteService::class,'execute'))->name('deep_bucket');
        $second=new Router();
        $second->import($second_source->export(),true,true);
        $this->assertSame('deep_bucket',$second->find('GET','/user/detail/8')[0]->getName());
    }

    /**
     * 测试: 懒加载下通配路由与桶内路由同键时按注册序取舍
     *
     * @return void
     */
    public function testLazyBucketTieBreaksByRegistrationOrder(): void {
        $source=new Router();
        $source->get('/{y}/aa',array(ConcreteService::class,'execute'))->name('catchall_first');
        $source->get('/aa/{x}',array(ConcreteService::class,'execute'))->name('bucket_second');

        $router=new Router();
        $router->import($source->export(),true,true);
        $this->assertSame('catchall_first',$router->find('GET','/aa/aa')[0]->getName());
    }

    /**
     * 测试: 懒加载下占位符嵌入段中的路由仍可命中
     *
     * @return void
     */
    public function testLazyRouteWithParamInsideFirstSegmentStillMatches(): void {
        $source=new Router();
        $source->get('/pre{name}/x',array(ConcreteService::class,'execute'))->name('partial');

        $router=new Router();
        $router->import($source->export(),true,true);
        $found=$router->find('GET','/prefix/x');
        $this->assertSame('partial',$found[0]->getName());
        $this->assertSame(array('name'=>'fix'),$found[1]);
    }

    /**
     * 测试: 懒加载下经解码候选命中首段含空格的桶
     *
     * @return void
     */
    public function testLazyBucketResolvedViaDecodedCandidate(): void {
        $source=new Router();
        $source->get('/hello world/{id}',array(ConcreteService::class,'execute'))->name('space');

        $router=new Router();
        $router->import($source->export(),true,true);
        $found=$router->find('GET','/hello%20world/9');
        $this->assertSame('space',$found[0]->getName());
        $this->assertSame(array('id'=>'9'),$found[1]);
    }
}
