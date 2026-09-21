<?php

namespace Tests;

use PHPUnit\Framework\TestCase;

use AdminService\App;
use AdminService\Config;
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
     * @var array<string>
     */
    private array $routesFiles=array();

    /**
     * 临时路由目录
     * @var string|null
     */
    private ?string $routesDir=null;

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
        foreach($this->routesFiles as $file)
            if(is_file($file))
                unlink($file);
        $this->routesFiles=array();
        if($this->routesDir!==null) {
            foreach((array)glob($this->routesDir.'/*.php') as $file)
                unlink($file);
            if(is_dir($this->routesDir))
                rmdir($this->routesDir);
            $this->routesDir=null;
        }
        Config::load();
    }

    /**
     * 写入单个临时路由文件并指向配置
     *
     * @access private
     * @param string $body 路由文件内容(不含 `<?php`)
     * @return void
     */
    private function useRoutes(string $body): void {
        $this->useRouteFiles(array($body));
    }

    /**
     * 写入多个临时路由文件并指向配置
     *
     * @access private
     * @param array<string> $bodies 各路由文件内容(不含 `<?php`)
     * @return void
     */
    private function useRouteFiles(array $bodies): void {
        foreach($bodies as $index=>$body) {
            $file=tempnam(sys_get_temp_dir(),'routes_'.$index.'_');
            file_put_contents($file,"<?php\n".$body."\n");
            $this->routesFiles[]=$file;
        }
        $configs=Config::all();
        $configs['route']['explicit']['files']=$this->routesFiles;
        Config::set($configs);
    }

    /**
     * 写入临时路由目录并指向配置
     *
     * @access private
     * @param array<string,string> $bodies 文件名(不含扩展名) => 文件内容(不含 `<?php`)
     * @return void
     */
    private function useRouteDir(array $bodies): void {
        $this->routesDir=sys_get_temp_dir().'/routes_dir_'.uniqid();
        mkdir($this->routesDir);
        foreach($bodies as $name=>$body)
            file_put_contents($this->routesDir.'/'.$name.'.php',"<?php\n".$body."\n");
        $configs=Config::all();
        $configs['route']['explicit']['files']=array($this->routesDir);
        Config::set($configs);
    }

    /**
     * 设置约定式回落开关
     *
     * @access private
     * @param bool $enabled 是否开启回落
     * @return void
     */
    private function setFallback(bool $enabled): void {
        $configs=Config::all();
        $configs['route']['convention_fallback']=$enabled;
        Config::set($configs);
    }

    /**
     * 分发一次请求
     *
     * - 每次分发前重置请求与返回值: 同一测试内多次分发时状态互不影响
     *
     * @access private
     * @param string $uri 请求路径
     * @param string $method 请求方法
     * @param array<string,mixed> $query 预先写入的 GET 参数
     * @return mixed 控制器返回值
     */
    private function dispatch(string $uri,string $method='GET',array $query=array()): mixed {
        HttpRequest::init();
        foreach($query as $key=>$value)
            HttpRequest::setGet($key,$value);
        Response::setControllerReturn(null);
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
        $this->setFallback(false);
        // 方法不匹配视为未命中
        $this->dispatch('/t/hello');
        $this->assertSame(404,Response::getStatusCode());
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
        $data=$this->dispatch('/t/param/hello',query:array('name'=>'other'));
        $this->assertSame('hello',$data['get']['name']);
    }

    /**
     * 测试路径参数约束不满足时不命中
     * @return void
     */
    public function testExplicitRouteConstraintNotMatched(): void {
        $this->useRoutes("\$router->get('/t/num/{id:\\d+}',array(\\app\\demo\\controller\\Index::class,'index'));");
        $this->setFallback(false);
        $this->assertSame('Hello World!',$this->dispatch('/t/num/42'));
        // 约束不满足视为未命中
        $this->dispatch('/t/num/abc');
        $this->assertSame(404,Response::getStatusCode());
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
     * 测试随项目提供的路由表可被加载
     *
     * - 使用真实配置(指向 `AdminService/routes` 目录), 覆盖按应用拆分与路径参数注入
     * - 仅使用不依赖数据库的端点
     *
     * @return void
     */
    public function testShippedRouteTable(): void {
        $this->assertSame('Hello World!',$this->dispatch('/demo/index/index'));
        $this->assertSame('Hello world!',$this->dispatch('/index/index/index/world'));
        $this->assertSame('Hello World!',$this->dispatch('/index'));
        // 该路由由控制器上的 #[Route] 属性声明, 经自动扫描注册
        $this->assertSame('Hello World!',$this->dispatch('/index/index/index'));
    }

    /**
     * 测试多文件路由(可按应用拆分)
     * @return void
     */
    public function testMultipleRouteFiles(): void {
        $this->useRouteFiles(array(
            "\$router->any('/t/a',array(\\app\\demo\\controller\\Index::class,'index'));",
            "\$router->any('/t/b',array(\\app\\demo\\controller\\Index::class,'index'));"
        ));
        $this->assertSame('Hello World!',$this->dispatch('/t/a'));
        $this->assertSame('Hello World!',$this->dispatch('/t/b'));
    }

    /**
     * 测试目录形式的路由加载(目录内全部 .php)
     * @return void
     */
    public function testRouteDirectory(): void {
        $this->useRouteDir(array(
            'demo'=>"\$router->any('/t/one',array(\\app\\demo\\controller\\Index::class,'index'));",
            'index'=>"\$router->any('/t/two',array(\\app\\demo\\controller\\Index::class,'index'));"
        ));
        $this->assertSame('Hello World!',$this->dispatch('/t/one'));
        $this->assertSame('Hello World!',$this->dispatch('/t/two'));
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
        $this->setFallback(true);
        $this->assertSame('Hello World!',$this->dispatch('/demo/index/index'));
    }

    /**
     * 测试未命中且未开启回落时返回 404
     *
     * - 不回显请求路径, 不泄漏目录结构
     *
     * @return void
     */
    public function testNotFoundReturns404(): void {
        $this->useRoutes('// 未注册任何路由');
        $this->setFallback(false);
        $this->dispatch('/demo/index/index');
        $this->assertSame(404,Response::getStatusCode());
        $this->assertSame('404 Not Found',Response::getControllerReturn());
    }

    /**
     * 测试约定式路径参数转换仍然生效
     * @return void
     */
    public function testConventionPathParameters(): void {
        $this->useRoutes('// 未注册任何路由');
        $this->setFallback(true);
        $data=$this->dispatch('/demo/index/request/name/hello');
        $this->assertSame('hello',$data['get']['name']);
    }

}
