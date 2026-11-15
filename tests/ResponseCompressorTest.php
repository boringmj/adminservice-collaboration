<?php

namespace Tests;

use PHPUnit\Framework\TestCase;

use AdminService\Exception;
use AdminService\Worker\ResponseCompressor;

use function gzdecode;
use function str_repeat;
use function strlen;

/**
 * 常驻 worker 响应压缩测试
 */
class ResponseCompressorTest extends TestCase {

    /**
     * 测试: 声明 gzip 且正文足够大时压缩并补充响应头
     *
     * @return void
     */
    public function testCompressesLargeBodyForGzipClient(): void {
        $body=str_repeat('compress-me-',300);
        $headers=array('content-type'=>array('text/html; charset=utf-8'),'vary'=>array('Accept'));
        $compressor=new ResponseCompressor(6);
        $compressed=$compressor->compress($body,'gzip, deflate',$headers);
        $this->assertNotNull($compressed);
        $this->assertSame($body,gzdecode($compressed));
        $this->assertLessThan(strlen($body),strlen($compressed));
        $this->assertSame(array('gzip'),$headers['content-encoding']);
        $this->assertSame(array('Accept','Accept-Encoding'),$headers['vary']);
    }

    /**
     * 测试: 客户端未声明 gzip 时不压缩
     *
     * @return void
     */
    public function testSkipsWithoutGzipAcceptEncoding(): void {
        $headers=array();
        $compressor=new ResponseCompressor();
        $this->assertNull($compressor->compress(str_repeat('a',2048),'br',$headers));
        $this->assertArrayNotHasKey('content-encoding',$headers);
    }

    /**
     * 测试: 正文小于最小长度时不压缩
     *
     * @return void
     */
    public function testSkipsSmallBody(): void {
        $headers=array();
        $compressor=new ResponseCompressor();
        $this->assertNull($compressor->compress('short','gzip',$headers));
        $this->assertArrayNotHasKey('vary',$headers);
    }

    /**
     * 测试: 已带 Content-Encoding 时不重复压缩
     *
     * @return void
     */
    public function testSkipsAlreadyEncodedResponse(): void {
        $headers=array('Content-Encoding'=>array('br'));
        $compressor=new ResponseCompressor();
        $this->assertNull($compressor->compress(str_repeat('a',2048),'gzip',$headers));
        $this->assertSame(array('br'),$headers['Content-Encoding']);
    }

    /**
     * 测试: 图片类型不压缩
     *
     * @return void
     */
    public function testSkipsImageContentType(): void {
        $headers=array('content-type'=>array('image/png'));
        $compressor=new ResponseCompressor();
        $this->assertNull($compressor->compress(str_repeat('a',2048),'gzip',$headers));
    }

    /**
     * 测试: 压缩级别必须在 1-9
     *
     * @return void
     */
    public function testRejectsInvalidLevel(): void {
        $this->expectException(Exception::class);
        new ResponseCompressor(0);
    }

}
