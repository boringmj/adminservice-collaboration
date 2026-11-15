<?php

namespace Tests;

use PHPUnit\Framework\TestCase;

use AdminService\Config\Repository;
use AdminService\HttpError;
use AdminService\HttpRequest;
use AdminService\Response;
use AdminService\ResponseProcessor\Http;
use AdminService\Worker\ResponseEmitter;

use function array_change_key_case;
use function fclose;
use function is_resource;
use function proc_close;
use function proc_open;
use function stream_get_contents;

/**
 * 流式响应测试
 */
class ResponseStreamTest extends TestCase {

    /**
     * 构造带内容类型登记的响应
     *
     * @access private
     * @return Response
     */
    private function response(): Response {
        return new Response(new Repository(array(
            'response'=>array('default'=>array(
                'negotiation'=>'off',
                'type'=>array(
                    'text/plain'=>array(
                        'class'=>Http::class,
                        'headers'=>array('Content-Type'=>'text/plain; charset=utf-8')
                    )
                )
            ))
        )));
    }

    /**
     * 构造请求
     *
     * @access private
     * @param string $method 请求方法
     * @return HttpRequest
     */
    private function request(string $method='GET'): HttpRequest {
        return new HttpRequest(array('server'=>array(
            'REQUEST_URI'=>'/',
            'REQUEST_METHOD'=>$method
        )));
    }

    /**
     * 测试: 流式断开处理方式可配置, 默认停止
     *
     * @return void
     */
    public function testStreamOnDisconnectOption(): void {
        $response=$this->response();
        $response->stream(static function(callable $write): void {
            $write('default');
        },'text/plain');
        $this->assertSame('stop',$response->streamOnDisconnect());
        $this->assertSame('stop',$response->export($this->request())['stream_on_disconnect']);

        $response=$this->response();
        $response->stream(static function(callable $write): void {
            $write('continue');
        },'text/plain',array('on_disconnect'=>'continue'));
        $data=$response->export($this->request());
        $this->assertSame('continue',$response->streamOnDisconnect());
        $this->assertSame('continue',$data['stream_on_disconnect']);

        $worker_response=(new ResponseEmitter())->emit($response,$this->request());
        $this->assertSame('continue',$worker_response->streamOnDisconnect());
    }

    /**
     * 测试: 流式断开处理方式取值无效时抛异常
     *
     * @return void
     */
    public function testInvalidStreamOnDisconnectThrows(): void {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('on_disconnect');
        $this->response()->stream(static function(callable $write): void {},'text/plain',array('on_disconnect'=>'finish'));
    }

    /**
     * 测试: 应用错误响应后清除流式生成器
     *
     * @return void
     */
    public function testHttpErrorClearsStream(): void {
        $response=$this->response();
        $response->stream(static function(callable $write): void {
            $write('streamed');
        },'text/plain');
        HttpError::notFound()->apply($response);
        $data=$response->export($this->request());
        $this->assertNull($data['stream']);
        $this->assertSame('404 Not Found',$data['body']);
    }

    /**
     * 测试: 显式指定但未登记的流式内容类型不被替换
     *
     * @return void
     */
    public function testExplicitUnregisteredStreamTypeIsKept(): void {
        $response=$this->response();
        $response->stream(static function(callable $write): void {
            $write('event');
        },'text/event-stream');
        $data=$response->export($this->request());
        $headers=array_change_key_case($data['headers'],CASE_LOWER);
        $this->assertSame('text/event-stream',$headers['content-type'][0]);
    }

    /**
     * 测试: 导出流式响应时返回生成器与空正文
     *
     * @return void
     */
    public function testExportReturnsStreamProducer(): void {
        $response=$this->response();
        $response->stream(static function(callable $write): void {
            $write('one');
            $write('two');
        },'text/plain');
        $data=$response->export($this->request());
        $this->assertSame('',$data['body']);
        $this->assertIsCallable($data['stream']);
        $headers=array_change_key_case($data['headers'],CASE_LOWER);
        $this->assertSame('text/plain; charset=utf-8',$headers['content-type'][0]);

        $chunks='';
        ($data['stream'])(static function(string $chunk) use (&$chunks): void {
            $chunks.=$chunk;
        });
        $this->assertSame('onetwo',$chunks);
    }

    /**
     * 测试: HEAD 与禁止正文的状态码不返回生成器
     *
     * @return void
     */
    public function testHeadAndNoBodyStatusSkipStream(): void {
        $response=$this->response();
        $response->stream(static function(callable $write): void {
            $write('head');
        },'text/plain');
        $head=$response->export($this->request('HEAD'));
        $this->assertNull($head['stream']);

        $response=$this->response();
        $response->stream(static function(callable $write): void {
            $write('empty');
        },'text/plain');
        $response->status(204);
        $nobody=$response->export($this->request());
        $this->assertNull($nobody['stream']);
    }

    /**
     * 测试: 流式响应优先于控制器返回值
     *
     * @return void
     */
    public function testStreamTakesPrecedenceOverBody(): void {
        $response=$this->response();
        $response->stream(static function(callable $write): void {
            $write('stream');
        },'text/plain');
        $response->body('plain');
        $this->assertTrue($response->hasStream());
        $data=$response->export($this->request());
        $this->assertIsCallable($data['stream']);
        $this->assertSame('',$data['body']);
    }

    /**
     * 测试: 导出为 worker 响应时保留生成器
     *
     * @return void
     */
    public function testEmitterCarriesStream(): void {
        $response=$this->response();
        $response->stream(static function(callable $write): void {
            $write('worker');
        },'text/plain');
        $worker_response=(new ResponseEmitter())->emit($response,$this->request());
        $this->assertSame('',$worker_response->body());
        $this->assertIsCallable($worker_response->stream());
    }

    /**
     * 测试: FPM 发送路径逐段输出
     *
     * @return void
     */
    public function testSendOutputStreamsChunks(): void {
        list($status,$stdout,$stderr)=$this->runFixture(__DIR__.'/fixtures/stream_response.php');
        $this->assertSame(0,$status,$stderr);
        $this->assertStringContainsString('|BEGIN|',$stdout);
        $this->assertStringContainsString('onetwo',$stdout);
        $this->assertStringContainsString('|END|',$stdout);
    }

    /**
     * 测试: 生成器异常只记录日志, 不向上抛出
     *
     * @return void
     */
    public function testSendOutputSurvivesProducerFailure(): void {
        list($status,$stdout,$stderr)=$this->runFixture(__DIR__.'/fixtures/stream_response_failure.php');
        $this->assertSame(0,$status,$stderr);
        $this->assertStringContainsString('partial',$stdout);
        $this->assertStringContainsString('Stream response failed',$stderr);
    }

    /**
     * 测试: 客户端断开不记录为流式失败
     *
     * @return void
     */
    public function testClientDisconnectIsNotLoggedAsFailure(): void {
        list($status,$stdout,$stderr)=$this->runFixture(__DIR__.'/fixtures/stream_response_disconnect.php');
        $this->assertSame(0,$status,$stderr);
        $this->assertStringContainsString('partial',$stdout);
        $this->assertStringNotContainsString('Stream response failed',$stderr);
    }

    /**
     * 运行夹具脚本
     *
     * @access private
     * @param string $script 脚本路径
     * @return array{0:int,1:string,2:string} 状态码 / stdout / stderr
     */
    private function runFixture(string $script): array {
        $process=proc_open(array(PHP_BINARY,'-d','display_errors=0','-d','output_buffering=0',$script),array(
            1=>array('pipe','w'),
            2=>array('pipe','w')
        ),$pipes);
        $this->assertTrue(is_resource($process),'无法启动子进程');
        $stdout=(string)stream_get_contents($pipes[1]);
        $stderr=(string)stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status=proc_close($process);
        return array($status,$stdout,$stderr);
    }

}
