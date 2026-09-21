<?php

namespace Tests;

use PHPUnit\Framework\TestCase;

use AdminService\App;
use AdminService\Config;
use AdminService\HttpRequest;

/**
 * 请求实例测试
 *
 * - 覆盖输入源注入、参数检索顺序、Input解析与实例隔离
 */
class RequestTest extends TestCase {

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
     * 每个测试后恢复配置
     * @return void
     */
    protected function tearDown(): void {
        Config::load();
    }

    /**
     * 覆盖配置项
     *
     * @access private
     * @param array<string,mixed> $values 配置(点分路径 => 值)
     * @return void
     */
    private function setConfig(array $values): void {
        $configs=Config::all();
        foreach($values as $path=>$value) {
            $keys=explode('.',$path);
            $node=&$configs;
            foreach($keys as $key)
                $node=&$node[$key];
            $node=$value;
        }
        Config::set($configs);
    }

    /**
     * 测试输入源注入: 不触碰超全局, 各来源互不串味
     * @return void
     */
    public function testInjectedSources(): void {
        $request=new HttpRequest(array(
            'query'=>array('name'=>'from-query','only_get'=>'g'),
            'post'=>array('name'=>'from-post'),
            'cookie'=>array('name'=>'from-cookie'),
            'headers'=>array('X-Custom'=>'custom-value'),
            'server'=>array('REQUEST_METHOD'=>'POST'),
            'rawInput'=>'{"a":1}',
            'files'=>array()
        ));
        $this->assertSame('g',$request->getGet('only_get'));
        $this->assertSame('from-query',$request->getGet('name'));
        $this->assertSame('from-post',$request->getPost('name'));
        $this->assertSame('from-cookie',$request->getCookie('name'));
        $this->assertSame('custom-value',$request->getHeader('x-custom'));
        $this->assertSame('POST',$request->getServer('REQUEST_METHOD'));
        $this->assertSame('{"a":1}',$request->getRawInput());
        // 数组形式取全部
        $this->assertSame(array('name'=>'from-query','only_get'=>'g'),$request->getGets());
        $this->assertSame(array('name'=>'from-post'),$request->getPosts());
        $this->assertSame(array('name'=>'from-cookie'),$request->getCookies());
    }

    /**
     * 测试未注入的输入源缺省为空(不读超全局)
     * @return void
     */
    public function testUndeclaredHeadersAreEmpty(): void {
        $request=new HttpRequest(array('query'=>array(),'server'=>array()));
        $this->assertSame(array(),$request->getHeaders());
        $this->assertSame('',$request->getRawInput());
    }

    /**
     * 测试请求头由注入的Server推导
     * @return void
     */
    public function testHeadersDerivedFromServer(): void {
        $request=new HttpRequest(array('server'=>array(
            'HTTP_X_TOKEN'=>'tk',
            'CONTENT_TYPE'=>'application/json',
            'REQUEST_URI'=>'/a'
        )));
        $this->assertSame('tk',$request->getHeader('x-token'));
        $this->assertSame('application/json',$request->getHeader('content-type'));
    }

    /**
     * 测试参数检索顺序由配置决定
     *
     * - 默认 CGP: 按 Cookie → Get → Post 的顺序检索, 靠前者优先
     *
     * @return void
     */
    public function testParamOrderFollowsConfig(): void {
        $sources=array(
            'query'=>array('name'=>'from-query'),
            'post'=>array('name'=>'from-post'),
            'cookie'=>array('name'=>'from-cookie')
        );
        $this->assertSame('from-cookie',(new HttpRequest($sources))->getParam('name'));
        // 调整为 GCP 后 Get 优先
        $this->setConfig(array('request.default.param.order'=>'GCP'));
        $this->assertSame('from-query',(new HttpRequest($sources))->getParam('name'));
    }

    /**
     * 测试按单一来源取参
     * @return void
     */
    public function testParamBySingleSource(): void {
        $request=new HttpRequest(array(
            'query'=>array('name'=>'from-query'),
            'post'=>array('name'=>'from-post')
        ));
        $this->assertSame('from-query',$request->getParam('name',HttpRequest::GET_PARAM));
        $this->assertSame('from-post',$request->getParam('name',HttpRequest::POST_PARAM));
        $this->assertNull($request->getParam('name',HttpRequest::COOKIE_PARAM));
        $this->assertSame('fallback',$request->getParam('missing',HttpRequest::ALL_PARAM,'fallback'));
    }

    /**
     * 测试参数键名按顺序合并去重
     * @return void
     */
    public function testParamKeys(): void {
        $request=new HttpRequest(array(
            'query'=>array('a'=>1,'b'=>2),
            'post'=>array('b'=>3,'c'=>4)
        ));
        $this->assertSame(array('a','b','c'),$request->getParamKeys());
        $this->assertSame(array('a','b'),$request->getParamKeys(HttpRequest::GET_PARAM));
        $this->assertSame(array('b','c'),$request->getParamKeys(HttpRequest::POST_PARAM));
    }

    /**
     * 测试按类型写入与删除参数
     * @return void
     */
    public function testSetAndRemoveParam(): void {
        $request=new HttpRequest(array('query'=>array('keep'=>1)));
        $request->setParam('added','v',HttpRequest::GET_PARAM);
        $this->assertSame('v',$request->getGet('added'));
        $this->assertNull($request->getPost('added'));
        $request->removeParam('added',HttpRequest::GET_PARAM);
        $this->assertNull($request->getGet('added'));
        $this->assertSame(1,$request->getGet('keep'));
    }

    /**
     * 测试Header键名大小写不敏感
     * @return void
     */
    public function testHeadersAreCaseInsensitive(): void {
        $request=new HttpRequest(array('headers'=>array('Accept'=>'application/json')));
        $this->assertSame('application/json',$request->getHeader('Accept'));
        $this->assertSame('application/json',$request->getHeader('accept'));
        $this->assertSame('application/json',$request->getHeader('ACCEPT'));
    }

    /**
     * 测试JSON请求体按Content-Type解析进Input
     * @return void
     */
    public function testJsonInputParsed(): void {
        $request=$this->jsonRequest('{"name":"fromjson","n":7}');
        $this->assertSame(array('name'=>'fromjson','n'=>7),$request->getInputs());
        $this->assertSame('fromjson',$request->getInput('name'));
    }

    /**
     * 测试Input按配置合并进指定来源
     * @return void
     */
    public function testInputMergedByConfig(): void {
        // 默认合并进POST
        $request=$this->jsonRequest('{"name":"fromjson"}');
        $this->assertSame('fromjson',$request->getPost('name'));
        $this->assertNull($request->getGet('name'));
        // 调整为合并进GET
        $this->setConfig(array('request.default.param.input'=>HttpRequest::GET_PARAM));
        $request=$this->jsonRequest('{"name":"fromjson"}');
        $this->assertSame('fromjson',$request->getGet('name'));
        $this->assertNull($request->getPost('name'));
        // 置0则不合并
        $this->setConfig(array('request.default.param.input'=>0));
        $request=$this->jsonRequest('{"name":"fromjson"}');
        $this->assertNull($request->getGet('name'));
        $this->assertNull($request->getPost('name'));
        $this->assertSame('fromjson',$request->getInput('name'));
    }

    /**
     * 测试非JSON请求体不进入Input
     * @return void
     */
    public function testNonJsonBodyNotParsed(): void {
        $request=new HttpRequest(array(
            'headers'=>array('Content-Type'=>'application/x-www-form-urlencoded'),
            'rawInput'=>'name=form'
        ));
        $this->assertSame(array(),$request->getInputs());
        $this->assertSame('name=form',$request->getRawInput());
    }

    /**
     * 测试无上传时表单为空(且不读上传配置、不建目录)
     * @return void
     */
    public function testUploadFormIsEmptyWithoutFiles(): void {
        $request=new HttpRequest(array('files'=>array()));
        $this->assertSame(0,count($request->getUploadFilesInstance()));
        $this->assertSame(0,count($request->getUploadFiles('files')));
        $this->assertSame(array(),$request->getUploadFilesInstance()->toArray());
    }

    /**
     * 测试实例之间互不干扰
     *
     * - 去静态的核心收益: 同一个进程内多个请求实例各自独立
     *
     * @return void
     */
    public function testInstancesAreIsolated(): void {
        $first=new HttpRequest(array('query'=>array('name'=>'first')));
        $second=new HttpRequest(array('query'=>array('name'=>'second')));
        $first->setParam('only_first','1',HttpRequest::GET_PARAM);
        $this->assertSame('first',$first->getGet('name'));
        $this->assertSame('second',$second->getGet('name'));
        $this->assertNull($second->getGet('only_first'));
        // 写入只落在被改的实例上
        $this->assertSame(array('name'=>'first','only_first'=>'1'),$first->getGets());
        $this->assertSame(array('name'=>'second'),$second->getGets());
    }

    /**
     * 构造一个JSON请求
     *
     * @access private
     * @param string $body 请求体
     * @return HttpRequest
     */
    private function jsonRequest(string $body): HttpRequest {
        return new HttpRequest(array(
            'headers'=>array('Content-Type'=>'application/json; charset=utf-8'),
            'rawInput'=>$body
        ));
    }

}
