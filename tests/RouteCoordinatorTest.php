<?php

namespace Tests;

use PHPUnit\Framework\TestCase;

use AdminService\App;
use AdminService\Config;
use AdminService\Exception;
use AdminService\HttpRequest;
use AdminService\Response;
use AdminService\Route;
use Tests\Fixtures\LabelMiddleware;
use Tests\Fixtures\MiddlewareLog;

/**
 * 路由协调器测试
 *
 * - 覆盖显式路由命中、路径参数注入、约定式回落与回落开关
 */
class RouteCoordinatorTest extends TestCase {

    /**
     * 临时路由文件路径
     * @var string|null
     */
    private ?string $routesFile=null;

    /**
     * 类初始化前执行
     * @return void
     */
    public static function setUpBeforeClass(): void {
        App::init();
    }

    /**
     * 每个测试前重置请求与响应
     * @return void
     */
    protected function setUp(): void {
        HttpRequest::init();
        Response::init();
    }

    /**
     * 每个测试后清理临时文件并恢复配置
     * @return void
     */
    protected function tearDown(): void {
        if($this->routesFile!==null&&is_file($this->routesFile))
            unlink($this->routesFile);
        $this->routesFile=null;
        Config::load();
    }

    /**
     * 写入临时路由文件并指向配置
     *
     * @access private
     * @param string $body 路由文件内容(不含 `<?php`)
     * @return void
     */
    private function useRoutes(string $body): void {
        $this->routesFile=tempnam(sys_get_temp_dir(),'routes_');
        file_put_contents($this->routesFile,"<?php\n".$body."\n");
        $configs=Config::all();
        $configs['route']['explicit']['file']=$this->routesFile;
        Config::set($configs);
    }

    /**
     * 分发一次请求
     *
     * @access private
     * @param string $uri 请求路径
     * @param string $method 请求方法
     * @return mixed 控制器返回值
     */
    private function dispatch(string $uri,string $method='GET'): mixed {
        HttpRequest::setServer('REQUEST_URI',$uri);
        HttpRequest::setServer('REQUEST_METHOD',$method);
        (new Route())->run();
        return Response::getControllerReturn();
    }

    /**
     * 测试显式路由命中
     * @return void
     */
    public function testExplicitRouteMatches(): void {
        $this->useRoutes("\$router->get('/t/hello',array(\\app\\demo\\controller\\Index::class,'index'));");
        $this->assertSame('Hello World!',$this->dispatch('/t/hello'));
    }

    /**
     * 测试显式路由区分请求方法
     * @return void
     */
    public function testExplicitRouteMethodDistinction(): void {
        $this->useRoutes("\$router->post('/t/hello',array(\\app\\demo\\controller\\Index::class,'index'));");
        // 方法不匹配则回落约定式, 该路径不构成约定式路由
        $this->expectException(Exception::class);
        $this->dispatch('/t/hello');
    }

    /**
     * 测试路径参数注入(并入 GET 参数)
     * @return void
     */
    public function testExplicitRoutePathParameter(): void {
        $this->useRoutes("\$router->get('/t/param/{name}',array(\\app\\demo\\controller\\Index::class,'request'));");
        $data=$this->dispatch('/t/param/hello');
        $this->assertSame('hello',$data['get']['name']);
    }

    /**
     * 测试路径参数优先于同名查询参数
     * @return void
     */
    public function testPathParameterOverridesQuery(): void {
        $this->useRoutes("\$router->get('/t/param/{name}',array(\\app\\demo\\controller\\Index::class,'request'));");
        HttpRequest::setGet('name','other');
        $data=$this->dispatch('/t/param/hello');
        $this->assertSame('hello',$data['get']['name']);
    }

    /**
     * 测试路径参数约束不满足时不命中
     * @return void
     */
    public function testExplicitRouteConstraintNotMatched(): void {
        $this->useRoutes("\$router->get('/t/num/{id:\\d+}',array(\\app\\demo\\controller\\Index::class,'index'));");
        $this->assertSame('Hello World!',$this->dispatch('/t/num/42'));
        // 约束不满足则回落约定式, 该路径不构成约定式路由
        $this->expectException(Exception::class);
        $this->dispatch('/t/num/abc');
    }

    /**
     * 测试显式路由下 App 路由上下文可用
     *
     * - 显式路由不含 app/controller/method 三段, 上下文由处理器类名推断
     *
     * @return void
     */
    public function testExplicitRouteAppContext(): void {
        $this->useRoutes("\$router->get('/t/ctx',array(\\app\\demo\\controller\\Index::class,'index'));");
        $this->dispatch('/t/ctx');
        $this->assertSame('demo',App::getAppName());
        $this->assertSame('Index',App::getControllerName());
        $this->assertSame('index',App::getMethodName());
    }

    /**
     * 测试中间件四级执行顺序
     *
     * - 全局 → 分组 → 路由 → 控制器, 由外向内包裹
     *
     * @return void
     */
    public function testMiddlewareLevelsOrder(): void {
        MiddlewareLog::clear();
        $configs=Config::all();
        $configs['middlewares']['global']=array(new LabelMiddleware('global'));
        $configs['middlewares']['controller']=array(new LabelMiddleware('controller'));
        Config::set($configs);
        $this->useRoutes(<<<'PHP'
$router->group(array('middleware'=>array(new \Tests\Fixtures\LabelMiddleware('group'))),function($router): void {
    $router->any('/t/mw',array(\app\demo\controller\Index::class,'index'))
        ->middleware(new \Tests\Fixtures\LabelMiddleware('route'));
});
PHP
        );
        $this->assertSame('Hello World!',$this->dispatch('/t/mw'));
        $this->assertSame(array(
            'global','group','route','controller',
            'controller:after','route:after','group:after','global:after'
        ),MiddlewareLog::$calls);
    }

    /**
     * 测试约定式回落
     *
     * - 显式路由表未覆盖该路径时, 按 /app/controller/method 回落
     *
     * @return void
     */
    public function testConventionFallback(): void {
        $this->useRoutes('// 未注册任何路由');
        $this->assertSame('Hello World!',$this->dispatch('/demo/Index/index'));
    }

    /**
     * 测试约定式回落关闭时未命中抛异常
     * @return void
     */
    public function testConventionFallbackDisabled(): void {
        $this->useRoutes('// 未注册任何路由');
        $configs=Config::all();
        $configs['route']['convention_fallback']=false;
        Config::set($configs);
        $this->expectException(Exception::class);
        $this->dispatch('/demo/Index/index');
    }

    /**
     * 测试约定式路径参数转换仍然生效
     * @return void
     */
    public function testConventionPathParameters(): void {
        $this->useRoutes('// 未注册任何路由');
        $data=$this->dispatch('/demo/Index/request/name/hello');
        $this->assertSame('hello',$data['get']['name']);
    }

}
