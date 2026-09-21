<?php

namespace Tests;

use PHPUnit\Framework\TestCase;

use AdminService\App;
use AdminService\Config;
use AdminService\HttpRequest;
use AdminService\Response;
use AdminService\Route;
use AdminService\Router\Router;
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
            file_put_contents($file,self::routeFileSource($body));
            $this->routesFiles[]=$file;
        }
        $configs=Config::all();
        $configs['route']['files']=$this->routesFiles;
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
            file_put_contents($this->routesDir.'/'.$name.'.php',self::routeFileSource($body));
        $configs=Config::all();
        $configs['route']['files']=array($this->routesDir);
        Config::set($configs);
    }

    /**
     * 生成路由文件源码(按约定返回接收路由表的闭包)
     *
     * @access private
     * @param string $body 路由注册语句
     * @return string
     */
    private static function routeFileSource(string $body): string {
        return "<?php\nreturn function(\\AdminService\\Router\\Router \$router): void {\n".$body."\n};\n";
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
        // 路径存在但方法不符 → 405, 并告知允许的方法
        $this->dispatch('/t/hello');
        $this->assertSame(405,Response::getStatusCode());
        $this->assertSame('POST',Response::getHeader('Allow'));
    }

    /**
     * 测试 HEAD 由 GET 路由承接
     * @return void
     */
    public function testHeadServedByGet(): void {
        $this->useRoutes("\$router->get('/t/g',array(\\app\\demo\\controller\\Index::class,'index'));");
        $data=$this->dispatch('/t/g','HEAD');
        $this->assertSame('Hello World!',$data);
        $this->assertSame(200,Response::getStatusCode());
    }

    /**
     * 测试 OPTIONS 自动应答(204 + Allow)
     * @return void
     */
    public function testOptionsAutoRespond(): void {
        $this->useRoutes("\$router->get('/t/o',array(\\app\\demo\\controller\\Index::class,'index'));");
        $this->dispatch('/t/o','OPTIONS');
        $this->assertSame(204,Response::getStatusCode());
        $this->assertSame('GET, HEAD',Response::getHeader('Allow'));
    }

    /**
     * 测试路径不存在时仍为 404
     * @return void
     */
    public function testUnknownPathReturns404(): void {
        $this->useRoutes("\$router->get('/t/only',array(\\app\\demo\\controller\\Index::class,'index'));");
        $this->dispatch('/t/other');
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
        // index 应用由控制器属性声明: 类级 #[RouteGroup('/index')] + 方法级可选参数
        $this->assertSame('Hello World!',$this->dispatch('/index'));
        $this->assertSame('Hello world!',$this->dispatch('/index/world'));
    }

    /**
     * 测试运行时用路由名反向生成 URL
     *
     * - 路由名注册进容器, 控制器/视图中经 App::get(Router::class) 取用
     *
     * @return void
     */
    public function testUrlGenerationAtRuntime(): void {
        $this->dispatch('/index');
        $router=App::get(Router::class);
        $this->assertSame('/index',$router->url('index.index'));
        $this->assertSame('/index/world',$router->url('index.index',array('name'=>'world')));
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
     * 测试未命中时返回 404
     *
     * - 不回显请求路径, 不泄漏目录结构
     *
     * @return void
     */
    public function testNotFoundReturns404(): void {
        $this->useRoutes('// 未注册任何路由');
        $this->dispatch('/demo/index/index');
        $this->assertSame(404,Response::getStatusCode());
        $this->assertSame('404 Not Found',Response::getControllerReturn());
    }


}
