<?php

namespace Tests;

use PHPUnit\Framework\TestCase;

use base\Request;
use base\Response;
use base\RouteContextInterface;
use AdminService\App;
use AdminService\Config;
use AdminService\HttpRequest;
use AdminService\Main;
use AdminService\Response as HttpResponse;
use AdminService\Route;
use AdminService\Router\Router;
use Tests\Fixtures\LabelMiddleware;
use Tests\Fixtures\MiddlewareLog;

use function file_put_contents;
use function glob;
use function is_dir;
use function is_file;
use function mkdir;
use function rmdir;
use function sys_get_temp_dir;
use function tempnam;
use function uniqid;
use function unlink;

/**
 * 路由协调器测试
 *
 * - 覆盖显式路由命中、路径参数注入、未命中语义与中间件层级
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
     * 入口(持有 Application, 请求级容器由它承载)
     * @var Main|null
     */
    private ?Main $main=null;

    /**
     * 类初始化前执行
     * @return void
     */
    public static function setUpBeforeClass(): void {
        App::init();
    }

    /**
     * 每个测试前登记全新的请求与响应实例
     * @return void
     */
    protected function setUp(): void {
        App::instance(Request::class,new HttpRequest());
        App::instance(Response::class,new HttpResponse());
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
     * 获取当前响应实例
     *
     * @access private
     * @return Response
     */
    private function response(): Response {
        // 响应属请求级对象: 经 Application 的请求级容器取(与框架关停阶段的响应出口同一条路径)
        return $this->main()->application()->requestContainer()->get(Response::class);
    }

    /**
     * 入口实例(每个测试方法内复用, 请求级容器随之重建)
     *
     * @return Main
     */
    private function main(): Main {
        return $this->main??=new Main();
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
     * - 每次分发都新建请求与响应实例: 同一测试内多次分发时状态互不影响
     * - 请求输入源显式注入, 不触碰超全局
     * - 走与生产一致的入口 `Main::run()`, 请求中间件因此同样生效
     *
     * @access private
     * @param string $uri 请求路径
     * @param string $method 请求方法
     * @param array<string,mixed> $query 预先写入的 GET 参数
     * @return mixed 控制器返回值
     */
    private function dispatch(string $uri,string $method='GET',array $query=array()): mixed {
        App::instance(Request::class,new HttpRequest(array(
            'server'=>array(
                'REQUEST_URI'=>$uri,
                'REQUEST_METHOD'=>$method
            ),
            'query'=>$query
        )));
        App::instance(Response::class,new HttpResponse());
        $this->main()->run();
        return $this->response()->body();
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
        $this->assertSame(405,$this->response()->status());
        $this->assertSame('POST',$this->response()->header('Allow'));
    }

    /**
     * 测试 HEAD 由 GET 路由承接
     * @return void
     */
    public function testHeadServedByGet(): void {
        $this->useRoutes("\$router->get('/t/g',array(\\app\\demo\\controller\\Index::class,'index'));");
        $data=$this->dispatch('/t/g','HEAD');
        $this->assertSame('Hello World!',$data);
        $this->assertSame(200,$this->response()->status());
    }

    /**
     * 测试 OPTIONS 自动应答(204 + Allow)
     * @return void
     */
    public function testOptionsAutoRespond(): void {
        $this->useRoutes("\$router->get('/t/o',array(\\app\\demo\\controller\\Index::class,'index'));");
        $this->dispatch('/t/o','OPTIONS');
        $this->assertSame(204,$this->response()->status());
        $this->assertSame('GET, HEAD',$this->response()->header('Allow'));
    }

    /**
     * 测试路径不存在时仍为 404
     * @return void
     */
    public function testUnknownPathReturns404(): void {
        $this->useRoutes("\$router->get('/t/only',array(\\app\\demo\\controller\\Index::class,'index'));");
        $this->dispatch('/t/other');
        $this->assertSame(404,$this->response()->status());
    }

    /**
     * 测试通配捕获值按名注入控制器形参
     *
     * - 通配糖 `*` 的参数名固定为 `any`
     *
     * @return void
     */
    public function testWildcardValueInjected(): void {
        $this->useRoutes("\$router->get('/w/*',array(\\Tests\\Fixtures\\RouteController::class,'wildcard'));");
        $this->assertSame('any=a/b',$this->dispatch('/w/a/b'));
        $this->assertSame('any=',$this->dispatch('/w'));
    }

    /**
     * 测试路径参数写入属性区
     *
     * - 路径参数不再并入GET: `attributes` 可取到, 合并取值(`param`)时优先
     *
     * @return void
     */
    public function testExplicitRoutePathParameter(): void {
        $this->useRoutes("\$router->get('/t/param/{name}',array(\\app\\demo\\controller\\Index::class,'request'));");
        $data=$this->dispatch('/t/param/hello');
        $this->assertSame('hello',$data['attributes']['name']);
        $this->assertSame('hello',$data['param']);
        $this->assertArrayNotHasKey('name',$data['get']);
    }

    /**
     * 测试同名查询参数不被路径参数覆盖
     *
     * - 两者分区存放, 合并取值时路径参数优先
     *
     * @return void
     */
    public function testPathParameterDoesNotOverwriteQuery(): void {
        $this->useRoutes("\$router->get('/t/param/{name}',array(\\app\\demo\\controller\\Index::class,'request'));");
        $data=$this->dispatch('/t/param/hello',query:array('name'=>'other'));
        $this->assertSame('hello',$data['attributes']['name']);
        $this->assertSame('other',$data['get']['name']);
        $this->assertSame('hello',$data['param']);
    }

    /**
     * 测试形参只注入路由参数
     *
     * - 查询参数等输入不参与形参注入, 避免请求数据悄悄覆盖控制器形参
     *
     * @return void
     */
    public function testOnlyRouteParamsInjected(): void {
        $this->useRoutes("\$router->get('/inj/{name?}',array(\\Tests\\Fixtures\\InjectController::class,'greet'));");
        // 路径参数注入形参
        $this->assertSame('name=frompath',$this->dispatch('/inj/frompath'));
        // 查询参数不注入形参(形参保持默认值)
        $this->assertSame('name=default',$this->dispatch('/inj',query:array('name'=>'fromquery')));
        // 两者同时存在时形参取路径参数
        $this->assertSame('name=frompath',$this->dispatch('/inj/frompath',query:array('name'=>'fromquery')));
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
        $this->assertSame(404,$this->response()->status());
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
        // 路由上下文属**请求级对象**: 请求结束后门面已收回应用级容器, 故经请求级容器读取
        $container=$this->main()->application()->requestContainer();
        $context=$container->get(RouteContextInterface::class);
        $this->assertSame('demo',$context->appName());
        $this->assertSame('Index',$context->controllerName());
        $this->assertSame('index',$context->methodName());
        $this->assertSame(\app\demo\controller\Index::class,$context->controllerClass());
    }

    /**
     * 测试中间件可读取路径参数
     *
     * - 路径参数在管道执行前并入 GET, 中间件即拿到路由上下文
     *
     * @return void
     */
    public function testMiddlewareSeesRouteParams(): void {
        MiddlewareLog::clear();
        $this->useRoutes("\$router->get('/mw/{name}',array(\\app\\demo\\controller\\Index::class,'index'))->middleware(\\Tests\\Fixtures\\ParamMiddleware::class);");
        $this->assertSame('Hello World!',$this->dispatch('/mw/abc'));
        $this->assertSame(array('param:abc'),MiddlewareLog::$calls);
    }

    /**
     * 测试中间件不调用 $next 时中断后续
     *
     * @return void
     */
    public function testMiddlewareShortCircuit(): void {
        MiddlewareLog::clear();
        $this->useRoutes("\$router->get('/block',array(\\app\\demo\\controller\\Index::class,'index'))->middleware(\\Tests\\Fixtures\\BlockingMiddleware::class);");
        $this->dispatch('/block');
        $this->assertSame(array('blocked'),MiddlewareLog::$calls);
        // 控制器未执行, 无返回值(可据此自定义响应)
        $this->assertNull($this->response()->body());
    }

    /**
     * 测试中间件四级执行顺序
     *
     * - 请求 → 分组 → 路由 → 控制器, 由外向内包裹
     *
     * @return void
     */
    public function testMiddlewareLevelsOrder(): void {
        MiddlewareLog::clear();
        $configs=Config::all();
        $configs['middlewares']['request']=array(new LabelMiddleware('request'));
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
            'request','group','route','controller',
            'controller:after','route:after','group:after','request:after'
        ),MiddlewareLog::$calls);
    }

    /**
     * 测试请求中间件在匹配前执行
     *
     * - 未命中的请求(404)同样经过请求中间件, 便于统一跨域头与错误体
     *
     * @return void
     */
    public function testRequestMiddlewareRunsBeforeMatching(): void {
        MiddlewareLog::clear();
        $configs=Config::all();
        $configs['middlewares']['request']=array(new LabelMiddleware('request'));
        Config::set($configs);
        $this->useRoutes('// 未注册任何路由');
        $this->dispatch('/nope');
        $this->assertSame(404,$this->response()->status());
        $this->assertSame(array('request','request:after'),MiddlewareLog::$calls);
    }

    /**
     * 测试请求中间件不调用$next时中断路由匹配
     * @return void
     */
    public function testRequestMiddlewareShortCircuit(): void {
        MiddlewareLog::clear();
        $configs=Config::all();
        $configs['middlewares']['request']=array(new LabelMiddleware('request'));
        Config::set($configs);
        $this->useRoutes("\$router->get('/t/r',array(\\app\\demo\\controller\\Index::class,'index'))->middleware(new \\Tests\\Fixtures\\BlockingMiddleware());");
        $this->dispatch('/t/r');
        // 请求中间件进入路由, 路由中间件中断: 控制器未执行
        $this->assertSame(array('request','blocked','request:after'),MiddlewareLog::$calls);
        $this->assertNull($this->response()->body());
        $this->assertSame(200,$this->response()->status());
    }

    /**
     * 测试请求中间件的层内优先级
     *
     * - 配置中可用条目写法声明: 数值大者靠外
     *
     * @return void
     */
    public function testRequestMiddlewarePriority(): void {
        MiddlewareLog::clear();
        $configs=Config::all();
        $configs['middlewares']['request']=array(
            array('middleware'=>new LabelMiddleware('low'),'priority'=>1),
            array('middleware'=>new LabelMiddleware('high'),'priority'=>9)
        );
        Config::set($configs);
        $this->useRoutes("\$router->get('/t/p',array(\\app\\demo\\controller\\Index::class,'index'));");
        $this->assertSame('Hello World!',$this->dispatch('/t/p'));
        $this->assertSame(array('high','low','low:after','high:after'),MiddlewareLog::$calls);
    }

    /**
     * 测试层间顺序固定
     *
     * - 层内优先级不跨层: 分组/路由层优先级再高也排在请求层之内
     *
     * @return void
     */
    public function testMiddlewareLayersAreFixed(): void {
        MiddlewareLog::clear();
        $configs=Config::all();
        $configs['middlewares']['request']=array(
            array('middleware'=>new LabelMiddleware('request'),'priority'=>1)
        );
        Config::set($configs);
        $this->useRoutes(<<<'PHP'
$router->group(array('middleware'=>array(
    array('middleware'=>new \Tests\Fixtures\LabelMiddleware('group'),'priority'=>100)
)),function($router): void {
    $router->any('/t/layers',array(\app\demo\controller\Index::class,'index'))
        ->middleware(array(
            array('middleware'=>new \Tests\Fixtures\LabelMiddleware('route'),'priority'=>50)
        ));
});
PHP
        );
        $this->assertSame('Hello World!',$this->dispatch('/t/layers'));
        $this->assertSame(array(
            'request','group','route','route:after','group:after','request:after'
        ),MiddlewareLog::$calls);
    }

    /**
     * 测试控制器级中间件属性
     *
     * - 配置项、类上、方法上的声明同级: 按 priority 排序(数值大者靠外)
     * - 同优先级时按 配置 → 类 → 方法 的收集顺序(此处配置未给优先级, 故排在本层最内)
     * - 仅由路由文件指向的处理器同样按类与方法解析属性
     *
     * @return void
     */
    public function testControllerMiddlewareAttribute(): void {
        MiddlewareLog::clear();
        $configs=Config::all();
        $configs['middlewares']['controller']=array(new LabelMiddleware('config'));
        Config::set($configs);
        $this->useRoutes("\$router->get('/mw/attr',array(\\Tests\\Fixtures\\MiddlewaredController::class,'handle'));");
        $this->assertSame('mw-handled',$this->dispatch('/mw/attr'));
        $this->assertSame(array(
            'class_high','class_low','method_low','config',
            'config:after','method_low:after','class_low:after','class_high:after'
        ),MiddlewareLog::$calls);
    }

    /**
     * 测试方法上的中间件只对该方法生效
     * @return void
     */
    public function testControllerMiddlewareAttributeScope(): void {
        MiddlewareLog::clear();
        $this->useRoutes("\$router->get('/mw/plain',array(\\Tests\\Fixtures\\MiddlewaredController::class,'plain'));");
        $this->assertSame('mw-plain',$this->dispatch('/mw/plain'));
        // 类上的声明对全部方法生效, 方法上的只对该方法生效
        $this->assertSame(array(
            'class_high','class_low',
            'class_low:after','class_high:after'
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
        $router=$this->main()->application()->requestContainer()->get(Router::class);
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
        $this->assertSame(404,$this->response()->status());
        $this->assertSame('404 Not Found',$this->response()->body());
    }

}
