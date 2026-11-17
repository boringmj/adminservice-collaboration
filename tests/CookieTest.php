<?php

namespace Tests;

use PHPUnit\Framework\TestCase;

use AdminService\Config\Repository;
use AdminService\Cookie;
use AdminService\Exception;

/**
 * Cookie 默认属性测试
 */
class CookieTest extends TestCase {

    /**
     * 创建 Cookie 服务
     *
     * @access private
     * @param array<string,mixed> $cookie cookie 配置
     * @return Cookie
     */
    private function createCookie(array $cookie=array()): Cookie {
        return new Cookie(new Repository(array('cookie'=>$cookie)));
    }

    /**
     * 测试默认属性
     *
     * @return void
     */
    public function testDefaults(): void {
        $built=$this->createCookie()->buildByArray(array('name'=>'value'));
        $this->assertTrue($built['name']['httponly']);
        $this->assertFalse($built['name']['secure']);
        $this->assertSame('Lax',$built['name']['samesite']);
    }

    /**
     * 测试显式属性覆盖默认值
     *
     * @return void
     */
    public function testExplicitOptionsOverrideDefaults(): void {
        $cookie=$this->createCookie(array('httponly'=>true,'secure'=>true,'samesite'=>'Strict'));
        $built=$cookie->buildByArray(array('name'=>array(
            'value'=>'value',
            'httponly'=>false,
            'secure'=>false,
            'samesite'=>'Strict'
        )));
        $this->assertFalse($built['name']['httponly']);
        $this->assertFalse($built['name']['secure']);
        $this->assertSame('Strict',$built['name']['samesite']);
    }

    /**
     * 测试空串表示不设置 SameSite
     *
     * @return void
     */
    public function testEmptySameSiteIsAllowed(): void {
        $built=$this->createCookie(array('samesite'=>''))->buildByArray(array('name'=>'value'));
        $this->assertSame('',$built['name']['samesite']);
    }

    /**
     * 测试非法 SameSite 抛出异常
     *
     * @return void
     */
    public function testInvalidSameSiteThrows(): void {
        $this->expectException(Exception::class);
        $this->expectExceptionCode(100402);
        $this->createCookie()->buildByArray(array('name'=>array(
            'value'=>'value',
            'samesite'=>'Bad'
        )));
    }

    /**
     * 测试 SameSite=None 必须配合 secure
     *
     * @return void
     */
    public function testSameSiteNoneRequiresSecure(): void {
        $this->expectException(Exception::class);
        $this->expectExceptionCode(100403);
        $this->createCookie()->buildByArray(array('name'=>array(
            'value'=>'value',
            'samesite'=>'None',
            'secure'=>false
        )));
    }

    /**
     * 测试 SameSite=None 与 secure 同时设置
     *
     * @return void
     */
    public function testSameSiteNoneWithSecureIsAllowed(): void {
        $built=$this->createCookie()->buildByArray(array('name'=>array(
            'value'=>'value',
            'samesite'=>'None',
            'secure'=>true
        )));
        $this->assertSame('None',$built['name']['samesite']);
        $this->assertTrue($built['name']['secure']);
    }

}
