<?php

namespace Tests;

use PHPUnit\Framework\TestCase;

use AdminService\App;
use AdminService\Config;
use AdminService\HttpRequest;
use AdminService\Main;
use AdminService\Response as HttpResponse;
use AdminService\Router\RouteItem;
use base\Attribute\Method;
use base\Attribute\Produces;
use base\Response;
use Tests\Fixtures\ProducesController;

use function file_put_contents;
use function is_file;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

/**
 * 路由级 produces 前置内容协商测试
 */
class RouteProducesTest extends TestCase {

    /**
     * 临时路由文件路径
     * @var array<string>
     */
    private array $routesFiles=array();

    /**
     * 入口
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
     * 每个测试后清理临时文件并恢复配置
     * @return void
     */
    protected function tearDown(): void {
        foreach($this->routesFiles as $file)
            if(is_file($file))
                unlink($file);
        $this->routesFiles=array();
        load_framework_config();
    }

    /**
     * 入口实例
     *
     * @access private
     * @return Main
     */
    private function main(): Main {
        return $this->main??=new Main();
    }

    /**
     * 当前响应实例
     *
     * @access private
     * @return Response
     */
    private function response(): Response {
        return $this->main()->application()->requestContainer()->get(Response::class);
    }

    /**
     * 写入单个临时路由文件并指向配置
     *
     * @access private
     * @param string $body 路由文件内容(不含 `<?php`)
     * @return void
     */
    private function useRoutes(string $body): void {
        $file=tempnam(sys_get_temp_dir(),'produces_routes_');
        file_put_contents($file,"<?php\nreturn function(\\AdminService\\Router\\Router \$router): void {\n".$body."\n};\n");
        $this->routesFiles[]=$file;
        $configs=Config::all();
        $configs['route']['files']=$this->routesFiles;
        set_config($configs);
    }

    /**
     * 设置内容协商策略
     *
     * @access private
     * @param string $policy 策略
     * @return void
     */
    private function usePolicy(string $policy): void {
        $configs=Config::all();
        $configs['response']['default']['negotiation']=$policy;
        set_config($configs);
    }

    /**
     * 派发一次请求
     *
     * @access private
     * @param string $uri 路径
     * @param string $method 方法
     * @param array<string,string> $headers 请求头
     * @return mixed 响应正文
     */
    private function dispatch(string $uri,string $method='GET',array $headers=array()): mixed {
        $this->main()->application()->seedRequest(
            new HttpRequest(array(
                'server'=>array(
                    'REQUEST_URI'=>$uri,
                    'REQUEST_METHOD'=>$method
                ),
                'query'=>array(),
                'headers'=>$headers
            )),
            new HttpResponse()
        );
        $this->main()->run();
        return $this->response()->body();
    }

    /**
     * 测试: 声明后可接受的类型完成协商
     *
     * @return void
     */
    public function testProducesMatchesAccept(): void {
        $this->useRoutes("\$router->get('/p/json',array(\\Tests\\Fixtures\\ProducesController::class,'jsonOnly'));");
        $this->dispatch('/p/json','GET',array('Accept'=>'application/json'));
        $this->assertSame('application/json',$this->response()->contentType());
        $this->assertSame(200,$this->response()->status());
        $this->assertStringContainsString('Accept',$this->response()->header('Vary'));
    }

    /**
     * 测试: 不可接受时在处理器执行前返回 406
     *
     * @return void
     */
    public function testStrictRejectsBeforeHandler(): void {
        $this->useRoutes("\$router->get('/p/effect',array(\\Tests\\Fixtures\\ProducesController::class,'sideEffect'));");
        ProducesController::$marker='';
        $body=$this->dispatch('/p/effect','GET',array('Accept'=>'text/html'));
        $this->assertSame('406 Not Acceptable',$body);
        $this->assertSame(406,$this->response()->status());
        $this->assertSame('text/plain',$this->response()->contentType());
        $this->assertSame('',ProducesController::$marker,'406 应在处理器执行前产生');
    }

    /**
     * 测试: fallback 使用第一个声明类型
     *
     * @return void
     */
    public function testFallbackUsesFirstType(): void {
        $this->useRoutes("\$router->get('/p/json',array(\\Tests\\Fixtures\\ProducesController::class,'jsonOnly'));");
        $this->usePolicy('fallback');
        $this->assertSame(array('ok'=>true),$this->dispatch('/p/json','GET',array('Accept'=>'text/html')));
        $this->assertSame('application/json',$this->response()->contentType());
        $this->assertStringContainsString('Accept',$this->response()->header('Vary'));
    }

    /**
     * 测试: off 不读 Accept、不追加 Vary
     *
     * @return void
     */
    public function testOffSkipsNegotiation(): void {
        $this->useRoutes("\$router->get('/p/json',array(\\Tests\\Fixtures\\ProducesController::class,'jsonOnly'));");
        $this->usePolicy('off');
        $this->dispatch('/p/json','GET',array('Accept'=>'text/html'));
        $this->assertSame('application/json',$this->response()->contentType());
        $this->assertSame(200,$this->response()->status());
        $this->assertSame('',$this->response()->header('Vary'));
    }

    /**
     * 测试: 空 Accept 使用第一个声明类型
     *
     * @return void
     */
    public function testEmptyAcceptUsesFirstType(): void {
        $this->useRoutes("\$router->get('/p/json',array(\\Tests\\Fixtures\\ProducesController::class,'jsonOnly'));");
        $this->dispatch('/p/json');
        $this->assertSame('application/json',$this->response()->contentType());
        $this->assertSame(200,$this->response()->status());
    }

    /**
     * 测试: q=0 视为明确拒绝
     *
     * @return void
     */
    public function testRejectedByQZero(): void {
        $this->useRoutes("\$router->get('/p/json',array(\\Tests\\Fixtures\\ProducesController::class,'jsonOnly'));");
        $this->dispatch('/p/json','GET',array('Accept'=>'application/json;q=0'));
        $this->assertSame(406,$this->response()->status());
    }

    /**
     * 测试: vendor JSON 与标准 JSON 等价
     *
     * @return void
     */
    public function testVendorJsonMatches(): void {
        $this->useRoutes("\$router->get('/p/json',array(\\Tests\\Fixtures\\ProducesController::class,'jsonOnly'));");
        $this->dispatch('/p/json','GET',array('Accept'=>'application/vnd.api+json'));
        $this->assertSame('application/json',$this->response()->contentType());
        $this->assertSame(200,$this->response()->status());
    }

    /**
     * 测试: 显式类型属于声明列表时允许
     *
     * @return void
     */
    public function testExplicitTypeWithinProduces(): void {
        $this->useRoutes("\$router->get('/p/multi',array(\\Tests\\Fixtures\\ProducesController::class,'multi'));");
        $this->assertSame('html',$this->dispatch('/p/multi','GET',array('Accept'=>'application/json')));
        $this->assertSame('text/html',$this->response()->contentType());
    }

    /**
     * 测试: 显式类型不在声明列表时抛异常
     *
     * @return void
     */
    public function testExplicitTypeOutsideProducesThrows(): void {
        $this->useRoutes("\$router->get('/p/wrong',array(\\Tests\\Fixtures\\ProducesController::class,'wrongType'));");
        $this->expectException(\AdminService\Exception::class);
        $this->expectExceptionMessage('not declared by #[Produces]');
        $this->dispatch('/p/wrong','GET',array('Accept'=>'application/json'));
    }

    /**
     * 测试: 错误状态码不受 produces 校验
     *
     * @return void
     */
    public function testErrorStatusIsExempt(): void {
        $this->useRoutes("\$router->get('/p/error',array(\\Tests\\Fixtures\\ProducesController::class,'errorStatus'));");
        $this->assertSame('404 Not Found',$this->dispatch('/p/error','GET',array('Accept'=>'application/json')));
        $this->assertSame(404,$this->response()->status());
        $this->assertSame('text/plain',$this->response()->contentType());
    }

    /**
     * 测试: 204 仍按 produces 协商类型
     *
     * @return void
     */
    public function testNoContentStatusStillNegotiates(): void {
        $this->useRoutes("\$router->get('/p/204',array(\\Tests\\Fixtures\\ProducesController::class,'noContent'));");
        $this->dispatch('/p/204','GET',array('Accept'=>'application/json'));
        $this->assertSame(204,$this->response()->status());
        $this->assertSame('application/json',$this->response()->contentType());
    }

    /**
     * 测试: 204 路由在不可接受时同样在处理器前 406
     *
     * @return void
     */
    public function testNoContentRouteRejectsBeforeHandler(): void {
        $this->useRoutes("\$router->get('/p/204',array(\\Tests\\Fixtures\\ProducesController::class,'noContent'));");
        $this->assertSame('406 Not Acceptable',$this->dispatch('/p/204','GET',array('Accept'=>'text/html')));
        $this->assertSame(406,$this->response()->status());
    }

    /**
     * 测试: 未登记的 produces 类型原样下发
     *
     * @return void
     */
    public function testUnregisteredProducesTypeIsKept(): void {
        $this->useRoutes("\$router->get('/p/csv',array(\\Tests\\Fixtures\\ProducesController::class,'csv'));");
        $this->dispatch('/p/csv','GET',array('Accept'=>'text/csv'));
        $this->assertSame('text/csv',$this->response()->contentType());
        $this->response()->render();
        $this->assertSame('text/csv',$this->response()->header('Content-Type'));
    }

    /**
     * 测试: 204 下显式类型不参与校验
     *
     * @return void
     */
    public function testNoContentExplicitTypeIsExempt(): void {
        $this->useRoutes("\$router->get('/p/204-plain',array(\\Tests\\Fixtures\\ProducesController::class,'noContentPlain'));");
        $this->dispatch('/p/204-plain','GET',array('Accept'=>'application/json'));
        $this->assertSame(204,$this->response()->status());
        $this->assertSame('text/plain',$this->response()->contentType());
    }

    /**
     * 测试: HEAD 使用同一套协商
     *
     * @return void
     */
    public function testHeadUsesProducesNegotiation(): void {
        $this->useRoutes("\$router->get('/p/json',array(\\Tests\\Fixtures\\ProducesController::class,'jsonOnly'));");
        $this->dispatch('/p/json','HEAD',array('Accept'=>'application/json'));
        $this->assertSame(200,$this->response()->status());
        $this->assertSame('application/json',$this->response()->contentType());
    }

    /**
     * 测试: 参数化与非法类型被拒绝
     *
     * @return void
     */
    public function testMalformedProducesRejected(): void {
        foreach(array('text/plain; charset=utf-8','/','a/','/b','text/plain extra') as $type) {
            try {
                Produces::normalize($type);
                $this->fail('应拒绝非法类型: '.$type);
            } catch(\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    /**
     * 测试: 未声明 produces 的方法不做前置协商
     *
     * @return void
     */
    public function testNoProducesKeepsOutletNegotiation(): void {
        $this->useRoutes("\$router->get('/p/plain',array(\\Tests\\Fixtures\\ProducesController::class,'plain'));");
        $this->dispatch('/p/plain','GET',array('Accept'=>'application/json'));
        $this->assertSame('*/*',$this->response()->contentType());
        $this->assertSame('',$this->response()->header('Vary'));
    }

    /**
     * 测试: 集中式路由自动继承方法上的注解
     *
     * @return void
     */
    public function testCentralizedRouteInheritsAttribute(): void {
        $this->useRoutes("\$router->get('/p/central',array(\\Tests\\Fixtures\\ProducesController::class,'jsonOnly'));");
        $this->dispatch('/p/central','GET',array('Accept'=>'text/html'));
        $this->assertSame(406,$this->response()->status());
    }

    /**
     * 测试: 闭包处理器通过链式设置声明
     *
     * @return void
     */
    public function testClosureProducesChain(): void {
        $this->useRoutes("\$router->get('/p/closure',static fn(): string => 'x')->produces('application/json');");
        $this->dispatch('/p/closure','GET',array('Accept'=>'text/html'));
        $this->assertSame(406,$this->response()->status());
    }

    /**
     * 测试: 路由缓存导出与恢复保留 produces
     *
     * @return void
     */
    public function testRouteCacheRoundTrip(): void {
        $route=new RouteItem(array(Method::GET),'/p/cache',array(ProducesController::class,'jsonOnly'));
        $data=$route->export();
        $this->assertSame(array('application/json'),$data['produces']);
        $restored=RouteItem::fromArray($data);
        $this->assertSame(array('application/json'),$restored->getProduces());
    }

    /**
     * 测试: 非法类型声明被拒绝
     *
     * @return void
     */
    public function testInvalidProducesRejected(): void {
        $this->expectException(\InvalidArgumentException::class);
        Produces::normalize('text');
    }

}
