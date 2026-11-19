<?php

namespace Tests;

use PHPUnit\Framework\TestCase;

use AdminService\common\HttpHelper;
use AdminService\Exception;
use ReflectionMethod;

/**
 * HttpHelper 响应头解析测试
 *
 * - 响应头必须从真实响应头行解析, 状态行与空行跳过, 同名头后者覆盖前者
 * - 端到端 curl 行为(响应头随响应体返回)需要真实服务端, 属未验证项
 */
class HttpHelperTest extends TestCase {

    /**
     * 测试响应头行解析
     * @return void
     */
    public function testParsesRawHeaderLines(): void {
        $method=new ReflectionMethod(HttpHelper::class,'parseRawHeaders');
        $method->setAccessible(true);
        $headers=$method->invoke(
            null,
            array(
                'HTTP/1.1 200 OK',
                'Content-Type: application/json',
                'X-Dup: a',
                'X-Dup: b',
                ''
            )
        );
        $this->assertSame(array(
            'Content-Type'=>'application/json',
            'X-Dup'=>'b'
        ),$headers);
    }

    /**
     * 测试 POST 数组体 JSON 编码失败时抛异常, 不静默发空体
     * @return void
     */
    public function testPostArrayBodyJsonEncodeFailureThrows(): void {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('POST data cannot be encoded as JSON');
        HttpHelper::post('http://127.0.0.1:1/',array('bad'=>NAN));
    }

}
