<?php

namespace Tests;

use PHPUnit\Framework\TestCase;

use AdminService\Config\Repository;
use AdminService\Response;
use base\CookieOptions;
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

        $this->assertSame(array('name'=>'value'),$cookie->data);
    }

    /**
     * 测试: SameSite 与其余属性透传到 Cookie 服务
     *
     * @return void
     */
    public function testResponsePassesSameSite(): void {
        $cookie=new RecordingCookie();
        $response=new Response(new Repository(array()),$cookie);
        $options=new CookieOptions('value',123,'/path','example.com',true,false,'Strict');
        $response->cookie('name',$options);
        $response->sendHeaders();

        $this->assertInstanceOf(CookieOptions::class,$cookie->data['name']);
        $this->assertSame($options,$cookie->data['name']);
    }

    /**
     * 测试: 批量标量值按字符串回读
     *
     * @return void
     */
    public function testBulkScalarValueIsReadAsString(): void {
        $response=new Response(new Repository(array()),new RecordingCookie());
        $response->cookies(array('count'=>5));
        $this->assertSame('5',$response->cookie('count'));
    }

}
