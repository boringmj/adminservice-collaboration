<?php

namespace Tests;

use PHPUnit\Framework\TestCase;

use AdminService\App;
use AdminService\Exception;
use AdminService\Router\Pipeline;
use AdminService\Router\RouteItem;
use AdminService\Router\Router;
use Tests\Fixtures\FirstMiddleware;
use Tests\Fixtures\GroupedController;
use Tests\Fixtures\MiddlewareLog;
use Tests\Fixtures\RouteController;
use Tests\Fixtures\SecondMiddleware;

/**
 * 路由模块测试
 *
 * - 覆盖注册、分组、资源、命名、属性声明、匹配与中间件管道
 */
class RouterTest extends TestCase {

    /**
     * 类初始化前执行
     *
     * - 中间件管道经容器解析请求对象, 需先注册容器别名(与 Main::init 一致)
     *
     * @return void
     */
    public static function setUpBeforeClass(): void {
        App::init();
    }

    /**
     * 测试方法区分
     * @return void
     */
    public function testMethodDistinction(): void {
        $router=new Router();
        $router->get('/user',array('C','index'));
        $router->post('/user',array('C','store'));
        $this->assertSame(array('C','index'),$router->find('GET','/user')[0]->getHandler());
        $this->assertSame(array('C','store'),$router->find('POST','/user')[0]->getHandler());
        // 未注册的方法不命中
        $this->assertNull($router->find('DELETE','/user'));
    }

    /**
     * 测试不限方法的路由
     * @return void
     */
    public function testAnyMethod(): void {
        $router=new Router();
        $router->any('/ping',array('C','ping'));
        $this->assertNotNull($router->find('GET','/ping'));
        $this->assertNotNull($router->find('POST','/ping'));
        $this->assertNotNull($router->find('DELETE','/ping'));
    }

    /**
     * 测试路径参数提取
     * @return void
     */
    public function testPathParameterExtraction(): void {
        $router=new Router();
        $router->get('/user/{id}',array('C','show'));
        $found=$router->find('GET','/user/42');
        $this->assertInstanceOf(RouteItem::class,$found[0]);
        $this->assertSame(array('id'=>'42'),$found[1]);
        // 参数段不跨斜杠
        $this->assertNull($router->find('GET','/user/42/extra'));
    }

    /**
     * 测试多段参数提取
     * @return void
     */
    public function testMultiplePathParameters(): void {
        $router=new Router();
        $router->get('/user/{userId}/post/{postId}',array('C','show'));
        $found=$router->find('GET','/user/1/post/9');
        $this->assertSame(array('userId'=>'1','postId'=>'9'),$found[1]);
    }

    /**
     * 测试参数正则约束
     * @return void
     */
    public function testRegexConstraint(): void {
        $router=new Router();
        $router->get('/user/{id:\d+}',array('C','show'));
        $this->assertNotNull($router->find('GET','/user/42'));
        $this->assertNull($router->find('GET','/user/abc'));
    }

    /**
     * 测试静态路径优先于含参路径
     * @return void
     */
    public function testStaticPathBeforeDynamic(): void {
        $router=new Router();
        // 动态路由先注册, 静态路由仍应优先命中
        $router->get('/article/{id}',array('C','show'));
        $router->get('/article/create',array('C','create'));
        $this->assertSame(array('C','create'),$router->find('GET','/article/create')[0]->getHandler());
        $found=$router->find('GET','/article/7');
        $this->assertSame(array('C','show'),$found[0]->getHandler());
        $this->assertSame(array('id'=>'7'),$found[1]);
    }

    /**
     * 测试请求路径规范化(查询串与末尾斜杠)
     * @return void
     */
    public function testUriNormalization(): void {
        $router=new Router();
        $router->get('/user/{id}',array('C','show'));
        $this->assertNotNull($router->find('GET','/user/42?sort=desc'));
        $this->assertNotNull($router->find('GET','/user/42/'));
    }

    /**
     * 测试分组前缀与中间件继承
     * @return void
     */
    public function testGroupPrefixAndMiddleware(): void {
        $router=new Router();
        $router->group(array('prefix'=>'/api/v1','middleware'=>FirstMiddleware::class),function(Router $r): void {
            $r->get('/profile',array('C','profile'));
            $r->group(array('prefix'=>'/admin'),function(Router $r): void {
                $r->get('/users',array('C','users'));
            });
        });
        $profile=$router->find('GET','/api/v1/profile');
        $this->assertNotNull($profile);
        $this->assertSame(array(FirstMiddleware::class),$profile[0]->getMiddlewares());
        // 嵌套分组: 前缀拼接, 中间件继承
        $users=$router->find('GET','/api/v1/admin/users');
        $this->assertSame(array(FirstMiddleware::class),$users[0]->getMiddlewares());
        // 分组结束后前缀不再生效
        $router->get('/profile',array('C','root'));
        $this->assertSame(array('C','root'),$router->find('GET','/profile')[0]->getHandler());
    }

    /**
     * 测试全局中间件与分组中间件合并
     * @return void
     */
    public function testGlobalAndGroupMiddlewareMerge(): void {
        $router=new Router(array(FirstMiddleware::class));
        $router->group(array('middleware'=>SecondMiddleware::class),function(Router $r): void {
            $r->get('/mixed',array('C','mixed'));
        });
        $this->assertSame(
            array(FirstMiddleware::class,SecondMiddleware::class),
            $router->find('GET','/mixed')[0]->getMiddlewares()
        );
    }

    /**
     * 测试命名路由反向生成
     * @return void
     */
    public function testNamedRouteUrlGeneration(): void {
        $router=new Router();
        $router->get('/user/{id:\d+}',array('C','show'))->name('user.show');
        $router->get('/about',array('C','about'))->name('about');
        $this->assertSame('/user/42',$router->url('user.show',array('id'=>42)));
        $this->assertSame('/about',$router->url('about'));
    }

    /**
     * 测试反向生成缺少参数时抛异常
     * @return void
     */
    public function testUrlGenerationMissingParameter(): void {
        $router=new Router();
        $router->get('/user/{id}',array('C','show'))->name('user.show');
        $this->expectException(Exception::class);
        $router->url('user.show');
    }

    /**
     * 测试路由名不存在时抛异常
     * @return void
     */
    public function testUrlGenerationUnknownName(): void {
        $router=new Router();
        $this->expectException(Exception::class);
        $router->url('missing');
    }

    /**
     * 测试资源路由
     * @return void
     */
    public function testResourceRoutes(): void {
        $router=new Router();
        $router->resource('/article','AppController');
        $this->assertCount(7,$router->getRoutes());
        $this->assertSame('index',$router->find('GET','/article')[0]->getHandler()[1]);
        $this->assertSame('store',$router->find('POST','/article')[0]->getHandler()[1]);
        $this->assertSame('create',$router->find('GET','/article/create')[0]->getHandler()[1]);
        $this->assertSame('show',$router->find('GET','/article/5')[0]->getHandler()[1]);
        $this->assertSame('edit',$router->find('GET','/article/5/edit')[0]->getHandler()[1]);
        $this->assertSame('update',$router->find('PUT','/article/5')[0]->getHandler()[1]);
        $this->assertSame('update',$router->find('PATCH','/article/5')[0]->getHandler()[1]);
        $this->assertSame('destroy',$router->find('DELETE','/article/5')[0]->getHandler()[1]);
        // 资源路由自带命名
        $this->assertSame('/article/5',$router->url('article.show',array('id'=>5)));
    }

    /**
     * 测试属性声明注册
     * @return void
     */
    public function testAttributeRegistration(): void {
        $router=new Router();
        $router->registerAttributes(array(RouteController::class));
        $found=$router->find('GET','/hello/world');
        $this->assertNotNull($found);
        $this->assertSame(array(RouteController::class,'hello'),$found[0]->getHandler());
        $this->assertSame(array('name'=>'world'),$found[1]);
        // 不限方法声明
        $this->assertNotNull($router->find('POST','/ping'));
        // 未声明路由的方法不注册
        $this->assertNull($router->find('GET','/ignored'));
    }

    /**
     * 测试中间件管道执行顺序
     * @return void
     */
    public function testPipelineOrder(): void {
        MiddlewareLog::clear();
        $pipeline=new Pipeline(array(FirstMiddleware::class,SecondMiddleware::class));
        $pipeline->then(function(): void {
            MiddlewareLog::$calls[]='core';
        });
        $this->assertSame(
            array('first','second','core','second:after','first:after'),
            MiddlewareLog::$calls
        );
    }

    /**
     * 测试空管道直接执行核心逻辑
     * @return void
     */
    public function testEmptyPipeline(): void {
        MiddlewareLog::clear();
        $pipeline=new Pipeline();
        $pipeline->then(function(): void {
            MiddlewareLog::$calls[]='core';
        });
        $this->assertSame(array('core'),MiddlewareLog::$calls);
    }

    /**
     * 测试路径写法非法时抛异常
     * @return void
     */
    public function testInvalidRoutePath(): void {
        $router=new Router();
        $this->expectException(Exception::class);
        $router->get('/user/{bad-name}',array('C','show'));
    }

    /**
     * 测试路径参数名重复时抛异常
     * @return void
     */
    public function testDuplicateRouteParameter(): void {
        $router=new Router();
        $this->expectException(Exception::class);
        $router->get('/user/{id}/{id}',array('C','show'));
    }

    /**
     * 测试未命中返回 null
     * @return void
     */
    public function testNoMatchReturnsNull(): void {
        $router=new Router();
        $router->get('/user',array('C','index'));
        $this->assertNull($router->find('GET','/missing'));
    }

    /**
     * 测试可选参数(位于末尾时匹配与否均可)
     * @return void
     */
    public function testOptionalParameter(): void {
        $router=new Router();
        $router->get('/opt/{name?}',array('C','index'))->name('opt');
        // 提供与否都命中; 未提供时不注入该参数
        $this->assertSame(array(),$router->find('GET','/opt')[1]);
        $this->assertSame(array('name'=>'x'),$router->find('GET','/opt/x')[1]);
        // 可选参数只占一个路径段
        $this->assertNull($router->find('GET','/opt/x/y'));
        // 反向生成: 缺省时整段省略
        $this->assertSame('/opt',$router->url('opt'));
        $this->assertSame('/opt/x',$router->url('opt',array('name'=>'x')));
    }

    /**
     * 测试可选参数可带约束
     * @return void
     */
    public function testOptionalParameterWithConstraint(): void {
        $router=new Router();
        $router->get('/num/{id?:\d+}',array('C','index'));
        $this->assertSame(array(),$router->find('GET','/num')[1]);
        $this->assertSame(array('id'=>'42'),$router->find('GET','/num/42')[1]);
        $this->assertNull($router->find('GET','/num/abc'));
    }

    /**
     * 测试可选参数不在末尾时抛异常
     * @return void
     */
    public function testOptionalParameterMustBeAtEnd(): void {
        $router=new Router();
        $this->expectException(Exception::class);
        $router->get('/bad/{x?}/tail',array('C','index'));
    }

    /**
     * 测试任意请求方法
     * @return void
     */
    public function testArbitraryMethod(): void {
        $router=new Router();
        $router->match(array('PROPFIND'),'/dav',array('C','dav'));
        $this->assertNotNull($router->find('PROPFIND','/dav'));
        $this->assertNull($router->find('GET','/dav'));
    }

    /**
     * 测试控制器级分组属性(前缀 + 中间件 + 命名 + 可选参数 + 多路径)
     * @return void
     */
    public function testControllerGroupAttribute(): void {
        $router=new Router();
        $router->registerAttributes(array(GroupedController::class));
        // 前缀生效: 方法只声明子路径
        $found=$router->find('GET','/g');
        $this->assertNotNull($found);
        $this->assertSame('hello',$found[0]->getHandler()[1]);
        $this->assertSame(array(),$found[1]);
        $this->assertSame(array('name'=>'x'),$router->find('GET','/g/x')[1]);
        // 分组中间件继承到该控制器下的路由
        $this->assertSame(array(FirstMiddleware::class),$found[0]->getMiddlewares());
        // 命名可用于反向生成
        $this->assertSame('/g',$router->url('g.hello'));
        $this->assertSame('/g/y',$router->url('g.hello',array('name'=>'y')));
        // 同一处理器的多条路径
        $this->assertSame($router->find('GET','/g/a')[0]->getHandler(),$router->find('GET','/g/b')[0]->getHandler());
    }

    /**
     * 测试更具体的同级路由优先于通用路由
     *
     * - 通用路由先注册也不应遮蔽更具体的同级路由
     *
     * @return void
     */
    public function testSpecificRouteWinsOverCatchAll(): void {
        $router=new Router();
        $router->get('/index/{name?}',array('C','home'));
        $router->get('/index/urlDemo/{name?}',array('C','url_demo'));
        $this->assertSame(array('C','url_demo'),$router->find('GET','/index/urlDemo')[0]->getHandler());
        $this->assertSame(array('C','url_demo'),$router->find('GET','/index/urlDemo/x')[0]->getHandler());
        $this->assertSame(array('C','home'),$router->find('GET','/index')[0]->getHandler());
        $this->assertSame(array('C','home'),$router->find('GET','/index/other')[0]->getHandler());
    }

    /**
     * 测试重复注册(路径与方法均相同)时抛异常
     * @return void
     */
    public function testDuplicateRouteThrows(): void {
        $router=new Router();
        $router->get('/dup',array('C','a'));
        $this->expectException(Exception::class);
        $router->get('/dup',array('C','b'));
    }

    /**
     * 测试等价动态路由(匹配形状相同)时抛异常
     * @return void
     */
    public function testEquivalentDynamicRouteThrows(): void {
        $router=new Router();
        $router->get('/user/{id}',array('C','a'));
        $this->expectException(Exception::class);
        $router->get('/user/{uid}',array('C','b'));
    }

    /**
     * 测试同路径不同方法不算冲突
     * @return void
     */
    public function testSamePathDifferentMethodsAllowed(): void {
        $router=new Router();
        $router->get('/same',array('C','index'));
        $router->post('/same',array('C','store'));
        $this->assertSame(array('C','index'),$router->find('GET','/same')[0]->getHandler());
        $this->assertSame(array('C','store'),$router->find('POST','/same')[0]->getHandler());
    }

    /**
     * 测试不限方法的路由与具体方法冲突
     * @return void
     */
    public function testAnyConflictsWithSpecificMethod(): void {
        $router=new Router();
        $router->get('/mix',array('C','index'));
        $this->expectException(Exception::class);
        $router->any('/mix',array('C','any'));
    }

    /**
     * 测试静态路径与含参路径并存(不算冲突, 匹配时静态优先)
     * @return void
     */
    public function testStaticAndDynamicNotConflict(): void {
        $router=new Router();
        $router->get('/thing/{id}',array('C','show'));
        $router->get('/thing/new',array('C','create'));
        $this->assertSame(array('C','create'),$router->find('GET','/thing/new')[0]->getHandler());
        $this->assertSame(array('C','show'),$router->find('GET','/thing/9')[0]->getHandler());
    }

    /**
     * 测试属性声明与已注册路由冲突时抛异常
     * @return void
     */
    public function testAttributeConflictThrows(): void {
        $router=new Router();
        // RouteController::hello 上声明了 #[Route('GET','/hello/{name}')]
        $router->get('/hello/{name}',array('C','show'));
        $this->expectException(Exception::class);
        $router->registerAttributes(array(RouteController::class));
    }

    /**
     * 测试集中式路由文件加载
     * @return void
     */
    public function testLoadRouteFile(): void {
        $file=tempnam(sys_get_temp_dir(),'route_');
        file_put_contents(
            $file,
            "<?php\nreturn function(\\AdminService\\Router\\Router \$router): void {\n"
            ."    \$router->get('/from-file',array('C','file'));\n"
            ."};\n"
        );
        $router=new Router();
        $router->load($file);
        unlink($file);
        $this->assertNotNull($router->find('GET','/from-file'));
    }

    /**
     * 测试路由文件未返回可调用结构时抛异常
     * @return void
     */
    public function testLoadRouteFileWithoutCallableThrows(): void {
        $file=tempnam(sys_get_temp_dir(),'route_');
        file_put_contents($file,"<?php\n// 未返回闭包\n");
        $router=new Router();
        $this->expectException(Exception::class);
        try {
            $router->load($file);
        } finally {
            unlink($file);
        }
    }

    /**
     * 测试路由文件不存在时抛异常
     * @return void
     */
    public function testLoadMissingRouteFile(): void {
        $router=new Router();
        $this->expectException(Exception::class);
        $router->load(__DIR__.'/no-such-routes.php');
    }

}
