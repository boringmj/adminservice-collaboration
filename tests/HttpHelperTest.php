<?php

namespace Tests;

use PHPUnit\Framework\TestCase;

use AdminService\common\HttpHelper;
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

}
