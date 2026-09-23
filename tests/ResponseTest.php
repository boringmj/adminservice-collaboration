<?php

namespace Tests;

use PHPUnit\Framework\TestCase;

use AdminService\App;
use AdminService\Config;
use AdminService\HttpRequest;
use AdminService\Response;
use AdminService\ResponseProcessor\Json;

use function ob_get_clean;
use function ob_start;

/**
 * 响应实例测试
 *
 * - 覆盖状态码/Header/Cookie、内容协商、发送与处理器显式执行
 */
class ResponseTest extends TestCase {

    /**
     * 类初始化前执行
     * @return void
     */
    public static function setUpBeforeClass(): void {
        App::init();
    }

    /**
     * 每个测试前恢复配置
     * @return void
     */
    protected function setUp(): void {
        Config::load();
    }

    /**
     * 构造一个请求
     *
     * @access private
     * @param string $method 请求方法
     * @param string $accept Accept头
     * @return HttpRequest
     */
    private function request(string $method='GET',string $accept=''): HttpRequest {
        $headers=$accept===''?array():array('Accept'=>$accept);
        return new HttpRequest(array(
            'headers'=>$headers,
            'server'=>array('REQUEST_METHOD'=>$method)
        ));
    }

    /**
     * 测试状态码、Header与内容类型的读写
     * @return void
     */
    public function testStatusHeaderAndContentType(): void {
        $response=new Response();
        $this->assertSame(200,$response->status());
        $response->status(404);
        $this->assertSame(404,$response->status());
        $response->header('X-Token','tk');
        $this->assertSame('tk',$response->header('X-Token'));
        // 批量设置
        $response->headers(array('A'=>'1','B'=>'2'));
        $this->assertSame('1',$response->header('A'));
        $this->assertSame('2',$response->header('B'));
        $response->contentType('text/plain');
        $this->assertSame('text/plain',$response->contentType());
        $this->assertSame('text/plain',$response->getStandardContentType());
        // 未登记的类型回落到默认类型
        $this->assertSame('*/*',$response->getStandardContentType('application/unknown'));
    }

    /**
     * 测试Cookie写入与读取
     * @return void
     */
    public function testCookieWrite(): void {
        $response=new Response();
        $response->cookie('simple','v1');
        $this->assertSame('v1',$response->cookie('simple'));
        // 带属性的写法(属性作为后续参数)
        $response->cookie('with_attr','v2',60,'/');
        $this->assertSame('v2',$response->cookie('with_attr'));
        // 批量形式(键名为Cookie名)
        $response->cookies(array('named'=>array('value'=>'v3')));
        $this->assertSame('v3',$response->cookie('named'));
        // 未设置的Cookie返回空串
        $this->assertSame('',$response->cookie('missing'));
    }

    /**
     * 测试json/html/text快捷方法设置内容类型并回传数据
     * @return void
     */
    public function testContentTypeShortcuts(): void {
        $response=new Response();
        $this->assertSame(array('a'=>1),$response->json(array('a'=>1)));
        $this->assertSame('application/json',$response->contentType());
        $this->assertSame('<b>x</b>',$response->html('<b>x</b>'));
        $this->assertSame('text/html',$response->contentType());
        $this->assertSame('plain',$response->text('plain'));
        $this->assertSame('text/plain',$response->contentType());
    }

    /**
     * 测试按Accept协商渲染
     *
     * - `prepare()` 负责协商(与请求相关), `render()` 只按已就位的内容类型渲染
     *
     * @return void
     */
    public function testRenderByAccept(): void {
        // application/json → Json处理器(标量被数组包裹)
        $response=new Response();
        $response->body('body');
        $response->prepare($this->request('GET','application/json'));
        $this->assertSame('["body"]',$response->render());
        // text/plain → Http处理器(原样输出字符串)
        $response=new Response();
        $response->body('body');
        $response->prepare($this->request('GET','text/plain'));
        $this->assertSame('body',$response->render());
        // text/html → 数组被编码为JSON字符串
        $response=new Response();
        $response->body(array('a'=>1));
        $response->prepare($this->request('GET','text/html'));
        $this->assertSame('{"a":1}',$response->render());
        // 未 prepare 时按当前内容类型渲染
        $response=new Response();
        $response->body('body');
        $this->assertSame('body',$response->render());
    }

    /**
     * 测试显式指定的内容类型优先于Accept
     * @return void
     */
    public function testExplicitContentTypeWins(): void {
        $response=new Response();
        $response->body('body');
        $response->json();
        // 客户端要text/plain, 但控制器已显式指定json
        $response->prepare($this->request('GET','text/plain'));
        $this->assertSame('["body"]',$response->render());
        // 未协商: 不追加 Vary
        $this->assertSame('',$response->header('Vary'));
    }

    /**
     * 测试协商后合并该类型的默认Header
     * @return void
     */
    public function testNegotiatedHeadersMerged(): void {
        $response=new Response();
        $response->body('body');
        $response->prepare($this->request('GET','application/json'));
        $response->render();
        $this->assertSame('application/json; charset=utf-8',$response->header('Content-Type'));
        // 内容类型由请求头决定: 追加 Vary 告知缓存
        $this->assertSame('Accept',$response->header('Vary'));
    }

    /**
     * 测试协商的通配与q值语义
     * @return void
     */
    public function testNegotiationWildcards(): void {
        // 子类通配
        $response=new Response();
        $response->prepare($this->request('GET','text/*'));
        $response->render();
        $this->assertSame('text/html; charset=utf-8',$response->header('Content-Type'));
        // q 值高者优先
        $response=new Response();
        $response->prepare($this->request('GET','application/json;q=0.4, text/plain;q=0.9'));
        $response->render();
        $this->assertSame('text/plain; charset=utf-8',$response->header('Content-Type'));
        // q=0 明确拒绝
        $response=new Response();
        $response->prepare($this->request('GET','application/json;q=0, text/*'));
        $response->render();
        $this->assertSame('text/html; charset=utf-8',$response->header('Content-Type'));
    }

    /**
     * 测试渲染结果可复用
     * @return void
     */
    public function testRenderContentReused(): void {
        $response=new Response();
        $response->body('body');
        $response->prepare($this->request('GET','text/plain'));
        $this->assertSame('body',$response->render());
        // 已渲染内容直接复用, 不再走处理器
        $this->assertSame('body',$response->render());
        $response->rendered('forced');
        $this->assertSame('forced',$response->render());
    }

    /**
     * 测试改写控制器返回值或内容类型后渲染缓存失效
     * @return void
     */
    public function testRenderCacheInvalidatedOnWrite(): void {
        $response=new Response();
        $response->body('first');
        $this->assertSame('first',$response->render());
        // 改写控制器返回值
        $response->body('second');
        $this->assertSame('second',$response->render());
        // 改写内容类型(经 json() 快捷方法)
        $response->body('third');
        $response->json();
        $this->assertSame('["third"]',$response->render());
        // 直接写 rendered 不算失效, 按写入内容复用
        $response->rendered('manual');
        $this->assertSame('manual',$response->render());
    }

    /**
     * 测试同名多值Header
     * @return void
     */
    public function testMultiValueHeaders(): void {
        $response=new Response();
        $response->header('Vary','Accept');
        $response->addHeader('Vary','Accept-Encoding');
        $this->assertSame('Accept, Accept-Encoding',$response->header('Vary'));
        // 数组写法
        $response->headers(array('Cache-Control'=>array('no-store','no-cache')));
        $this->assertSame('no-store, no-cache',$response->header('Cache-Control'));
        // 覆盖
        $response->header('Vary','Origin');
        $this->assertSame('Origin',$response->header('Vary'));
        // 显式传 null 移除
        $response->header('Vary',null);
        $this->assertSame('',$response->header('Vary'));
    }

    /**
     * 测试发送输出响应体
     * @return void
     */
    public function testSendOutputsBody(): void {
        $response=new Response();
        $response->body('sent-body');
        $response->prepare($this->request('GET','text/plain'));
        ob_start();
        $response->send();
        $body=ob_get_clean();
        $this->assertSame('sent-body',$body);
    }

    /**
     * 测试HEAD请求不输出响应体
     * @return void
     */
    public function testHeadSendsNoBody(): void {
        $response=new Response();
        $response->body('sent-body');
        $response->prepare($this->request('HEAD','text/plain'));
        ob_start();
        $response->send();
        $body=ob_get_clean();
        $this->assertSame('',$body);
        // 头部仍然发送, 内容已渲染
        $this->assertSame('sent-body',$response->rendered());
    }

    /**
     * 测试响应处理器为显式执行(构造不再产生副作用)
     * @return void
     */
    public function testProcessorHandleIsExplicit(): void {
        $response=new Response();
        $response->body(array('a'=>1));
        $processor=App::new(Json::class,response:$response,config:array('flag'=>JSON_UNESCAPED_UNICODE));
        // 构造时未执行
        $this->assertNull($response->rendered());
        $processor->handle();
        $this->assertSame('{"a":1}',$response->rendered());
    }

    /**
     * 测试实例之间互不干扰
     * @return void
     */
    public function testInstancesAreIsolated(): void {
        $first=new Response();
        $second=new Response();
        $first->status(500);
        $first->header('X-Only','1');
        $first->cookie('only','1');
        $this->assertSame(200,$second->status());
        $this->assertSame('',$second->header('X-Only'));
        $this->assertSame('',$second->cookie('only'));
    }

}
