<?php

namespace Tests;

use PHPUnit\Framework\TestCase;

use AdminService\Config\Repository;
use AdminService\Response;
use Tests\Fixtures\RecordingCookie;

/**
 * Cookie 下发注入测试
 */
class CookieScopeTest extends TestCase {

    /**
     * 测试: Response 使用注入的 Cookie 服务
     *
     * @return void
     */
    public function testResponseUsesInjectedCookie(): void {
        $cookie=new RecordingCookie();
        $response=new Response(new Repository(array()),$cookie);
        $response->cookie('name','value');
        $response->sendHeaders();

        $this->assertSame(array(
            'name'=>array(
                'value'=>'value',
                'expire'=>null,
                'path'=>null,
                'domain'=>null,
                'secure'=>null,
                'httponly'=>null
            )
        ),$cookie->data);
    }

}
