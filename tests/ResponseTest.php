<?php

namespace Tests;

use PHPUnit\Framework\TestCase;

use AdminService\App;
use AdminService\Config;
use AdminService\HttpRequest;
use AdminService\Response;
use AdminService\ResponseProcessor\Json;

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
     * @return void
     */
    public function testRenderByAccept(): void {
        // application/json → Json处理器(标量被数组包裹)
        $response=new Response();
        $response->body('body');
        $this->assertSame('["body"]',$response->render($this->request('GET','application/json')));
        // text/plain → Http处理器(原样输出字符串)
        $response=new Response();
        $response->body('body');
        $this->assertSame('body',$response->render($this->request('GET','text/plain')));
        // text/html → 数组被编码为JSON字符串
        $response=new Response();
        $response->body(array('a'=>1));
        $this->assertSame('{"a":1}',$response->render($this->request('GET','text/html')));
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
        $this->assertSame('["body"]',$response->render($this->request('GET','text/plain')));
    }

    /**
     * 测试协商后合并该类型的默认Header
     * @return void
     */
    public function testNegotiatedHeadersMerged(): void {
        $response=new Response();
        $response->body('body');
        $response->render($this->request('GET','application/json'));
        $this->assertSame('application/json; charset=utf-8',$response->header('Content-Type'));
    }

    /**
     * 测试渲染结果可复用
     * @return void
     */
    public function testRenderContentReused(): void {
        $response=new Response();
        $response->body('body');
        $this->assertSame('body',$response->render($this->request('GET','text/plain')));
        // 已渲染内容直接复用, 不再协商
        $this->assertSame('body',$response->render($this->request('GET','application/json')));
        $response->rendered('forced');
        $this->assertSame('forced',$response->render($this->request('GET','application/json')));
    }

    /**
     * 测试发送输出响应体
     * @return void
     */
    public function testSendOutputsBody(): void {
        $response=new Response();
        $response->body('sent-body');
        ob_start();
        $response->send($this->request('GET','text/plain'));
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
        ob_start();
        $response->send($this->request('HEAD','text/plain'));
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
