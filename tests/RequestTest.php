<?php

namespace Tests;

use PHPUnit\Framework\TestCase;

use AdminService\App;
use AdminService\Config;
use AdminService\HttpRequest;

use function count;
use function explode;

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
        $this->assertSame('g',$request->query('only_get'));
        $this->assertSame('from-query',$request->query('name'));
        $this->assertSame('from-post',$request->post('name'));
        $this->assertSame('from-cookie',$request->cookie('name'));
        $this->assertSame('custom-value',$request->header('x-custom'));
        $this->assertSame('POST',$request->server('REQUEST_METHOD'));
        $this->assertSame('{"a":1}',$request->rawInput());
        // 数组形式取全部
        $this->assertSame(array('name'=>'from-query','only_get'=>'g'),$request->query());
        $this->assertSame(array('name'=>'from-post'),$request->post());
        $this->assertSame(array('name'=>'from-cookie'),$request->cookie());
    }

    /**
     * 测试未注入的输入源缺省为空(不读超全局)
     * @return void
     */
    public function testUndeclaredHeadersAreEmpty(): void {
        $request=new HttpRequest(array('query'=>array(),'server'=>array()));
        $this->assertSame(array(),$request->header());
        $this->assertSame('',$request->rawInput());
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
        $this->assertSame('tk',$request->header('x-token'));
        $this->assertSame('application/json',$request->header('content-type'));
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
        $this->assertSame('from-cookie',(new HttpRequest($sources))->param('name'));
        // 调整为 GCP 后 Get 优先
        $this->setConfig(array('request.default.param.order'=>'GCP'));
        $this->assertSame('from-query',(new HttpRequest($sources))->param('name'));
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
        $this->assertSame('from-query',$request->param('name',HttpRequest::GET_PARAM));
        $this->assertSame('from-post',$request->param('name',HttpRequest::POST_PARAM));
        $this->assertNull($request->param('name',HttpRequest::COOKIE_PARAM));
        $this->assertSame('fallback',$request->param('missing',HttpRequest::ALL_PARAM,'fallback'));
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
        $this->assertSame(array('a','b','c'),$request->paramKeys());
        $this->assertSame(array('a','b'),$request->paramKeys(HttpRequest::GET_PARAM));
        $this->assertSame(array('b','c'),$request->paramKeys(HttpRequest::POST_PARAM));
    }

    /**
     * 测试按类型写入与删除参数
     * @return void
     */
    public function testSetAndRemoveParam(): void {
        $request=new HttpRequest(array('query'=>array('keep'=>1)));
        $request->setParam('added','v',HttpRequest::GET_PARAM);
        $this->assertSame('v',$request->query('added'));
        $this->assertNull($request->post('added'));
        $request->removeParam('added',HttpRequest::GET_PARAM);
        $this->assertNull($request->query('added'));
        $this->assertSame(1,$request->query('keep'));
    }

    /**
     * 测试Header键名大小写不敏感
     * @return void
     */
    public function testHeadersAreCaseInsensitive(): void {
        $request=new HttpRequest(array('headers'=>array('Accept'=>'application/json')));
        $this->assertSame('application/json',$request->header('Accept'));
        $this->assertSame('application/json',$request->header('accept'));
        $this->assertSame('application/json',$request->header('ACCEPT'));
    }

    /**
     * 测试JSON请求体按Content-Type解析进Input
     * @return void
     */
    public function testJsonInputParsed(): void {
        $request=$this->jsonRequest('{"name":"fromjson","n":7}');
        $this->assertSame(array('name'=>'fromjson','n'=>7),$request->input());
        $this->assertSame('fromjson',$request->input('name'));
    }

    /**
     * 测试Input按配置合并进指定来源
     * @return void
     */
    public function testInputMergedByConfig(): void {
        // 默认合并进POST
        $request=$this->jsonRequest('{"name":"fromjson"}');
        $this->assertSame('fromjson',$request->post('name'));
        $this->assertNull($request->query('name'));
        // 调整为合并进GET
        $this->setConfig(array('request.default.param.input'=>HttpRequest::GET_PARAM));
        $request=$this->jsonRequest('{"name":"fromjson"}');
        $this->assertSame('fromjson',$request->query('name'));
        $this->assertNull($request->post('name'));
        // 置0则不合并
        $this->setConfig(array('request.default.param.input'=>0));
        $request=$this->jsonRequest('{"name":"fromjson"}');
        $this->assertNull($request->query('name'));
        $this->assertNull($request->post('name'));
        $this->assertSame('fromjson',$request->input('name'));
    }

    /**
     * 测试表单请求体按Content-Type解析
     *
     * - PHP 不会为 PUT/PATCH/DELETE 填充 `$_POST`, 故这类请求体由框架解析
     *
     * @return void
     */
    public function testFormBodyParsed(): void {
        $request=$this->formRequest('PUT','name=fromput&n=1');
        $this->assertSame(array('name'=>'fromput','n'=>'1'),$request->input());
        $this->assertSame('fromput',$request->input('name'));
        // 按配置合并进POST(默认)
        $this->assertSame('fromput',$request->post('name'));
        $this->assertSame('fromput',$request->param('name'));
        $this->assertSame('1',$request->param('n'));
        $this->assertSame('name=fromput&n=1',$request->rawInput());
    }

    /**
     * 测试未登记的请求体类型不解析
     * @return void
     */
    public function testUnknownBodyTypeNotParsed(): void {
        $request=new HttpRequest(array(
            'headers'=>array('Content-Type'=>'application/xml'),
            'rawInput'=>'<a>1</a>'
        ));
        $this->assertSame(array(),$request->input());
        $this->assertSame('<a>1</a>',$request->rawInput());
    }

    /**
     * 测试Accept判定
     *
     * - `accepts` 按 `Accept` 头(含通配)判断; `wantsJson` 只认明确要求JSON
     * - `expectsJson` 额外认可 Ajax 标记
     *
     * @return void
     */
    public function testAcceptJudgement(): void {
        $json=new HttpRequest(array('headers'=>array('Accept'=>'application/json')));
        $this->assertTrue($json->accepts('application/json'));
        $this->assertFalse($json->accepts('text/html'));
        $this->assertTrue($json->wantsJson());
        $this->assertTrue($json->expectsJson());
        // 结构化后缀
        $hal=new HttpRequest(array('headers'=>array('Accept'=>'application/hal+json')));
        $this->assertTrue($hal->wantsJson());
        // 全通配: 接受任意类型, 但不视为明确要求JSON
        $any=new HttpRequest(array('headers'=>array('Accept'=>'*/*')));
        $this->assertTrue($any->accepts('text/html'));
        $this->assertFalse($any->wantsJson());
        $this->assertFalse($any->expectsJson());
        // 无Accept头视为接受任意类型
        $none=new HttpRequest(array('headers'=>array()));
        $this->assertTrue($none->accepts('text/html'));
        $this->assertFalse($none->wantsJson());
        // Ajax标记
        $ajax=new HttpRequest(array('headers'=>array('X-Requested-With'=>'XMLHttpRequest')));
        $this->assertTrue($ajax->expectsJson());
        // q=0 明确拒绝
        $reject=new HttpRequest(array('headers'=>array('Accept'=>'*/*, application/json;q=0')));
        $this->assertFalse($reject->accepts('application/json'));
        $this->assertFalse($reject->wantsJson());
    }

    /**
     * 构造一个表单请求
     *
     * @access private
     * @param string $method 请求方法
     * @param string $body 请求体
     * @return HttpRequest
     */
    private function formRequest(string $method,string $body): HttpRequest {
        return new HttpRequest(array(
            'server'=>array('REQUEST_METHOD'=>$method),
            'headers'=>array('Content-Type'=>'application/x-www-form-urlencoded'),
            'rawInput'=>$body
        ));
    }

    /**
     * 测试无上传时表单为空(且不读上传配置、不建目录)
     * @return void
     */
    public function testUploadFormIsEmptyWithoutFiles(): void {
        $request=new HttpRequest(array('files'=>array()));
        $this->assertSame(0,count($request->files()));
        $this->assertSame(0,count($request->file('files')));
        $this->assertSame(array(),$request->files()->toArray());
    }

    /**
     * 测试属性区的读写
     * @return void
     */
    public function testAttributes(): void {
        $request=new HttpRequest(array('query'=>array('name'=>'from-query')));
        $this->assertSame(array(),$request->attributes());
        $request->setAttribute('name','from-route');
        $request->setAttribute('id',7);
        $this->assertSame('from-route',$request->attribute('name'));
        $this->assertSame(7,$request->attribute('id'));
        $this->assertSame('fallback',$request->attribute('missing','fallback'));
        $this->assertSame(array('name'=>'from-route','id'=>7),$request->attributes());
    }

    /**
     * 测试属性优先于任一输入区
     *
     * - 路由参数(属性)优先于查询参数, 但分区存放互不覆盖
     *
     * @return void
     */
    public function testAttributePrecedesInput(): void {
        $request=new HttpRequest(array(
            'query'=>array('name'=>'from-query'),
            'post'=>array('name'=>'from-post')
        ));
        $request->setAttribute('name','from-route');
        $this->assertSame('from-route',$request->param('name'));
        // 指定来源时不受属性影响
        $this->assertSame('from-query',$request->param('name',HttpRequest::GET_PARAM));
        // 分区数据未被改动
        $this->assertSame('from-query',$request->query('name'));
        $this->assertSame('from-post',$request->post('name'));
        // 键名汇总时属性在最前
        $this->assertSame(array('name'),$request->paramKeys());
        $request->setAttribute('id',1);
        $this->assertSame(array('name','id'),$request->paramKeys());
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
        $this->assertSame('first',$first->query('name'));
        $this->assertSame('second',$second->query('name'));
        $this->assertNull($second->query('only_first'));
        // 写入只落在被改的实例上
        $this->assertSame(array('name'=>'first','only_first'=>'1'),$first->query());
        $this->assertSame(array('name'=>'second'),$second->query());
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
