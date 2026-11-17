<?php

namespace Tests;

use PHPUnit\Framework\TestCase;

use AdminService\Config\Repository;
use AdminService\Cookie;
use AdminService\Exception;
use base\CookieOptions;

use function time;

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
        $built=$this->createCookie()->buildAll(array('name'=>'value'));
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
        $built=$cookie->buildAll(array('name'=>array(
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
        $built=$this->createCookie(array('samesite'=>''))->buildAll(array('name'=>'value'));
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
        $this->createCookie()->buildAll(array('name'=>array(
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
        $this->createCookie()->buildAll(array('name'=>array(
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
        $built=$this->createCookie()->buildAll(array('name'=>array(
            'value'=>'value',
            'samesite'=>'None',
            'secure'=>true
        )));
        $this->assertSame('None',$built['name']['samesite']);
        $this->assertTrue($built['name']['secure']);
    }

    /**
     * 测试绝对过期时间原样保留
     *
     * @return void
     */
    public function testAbsoluteExpiresIsPreserved(): void {
        $expires=time()+100;
        $built=$this->createCookie()->build('name',new CookieOptions('value',$expires));
        $this->assertSame($expires,$built['expire']);
    }

    /**
     * 测试旧数组键 expire 按相对秒数处理
     *
     * @return void
     */
    public function testLegacyExpireArrayIsRelative(): void {
        $built=$this->createCookie()->buildAll(array('name'=>array(
            'value'=>'value',
            'expire'=>60
        )));
        $now=time();
        $this->assertGreaterThanOrEqual($now+59,$built['name']['expire']);
        $this->assertLessThanOrEqual($now+61,$built['name']['expire']);
    }

    /**
     * 测试非法 Cookie 名称抛出异常
     *
     * @return void
     */
    public function testInvalidNameThrows(): void {
        $this->expectException(Exception::class);
        $this->expectExceptionCode(100405);
        $this->createCookie()->build('bad name','value');
    }

    /**
     * 测试值中的控制字符抛出异常
     *
     * @return void
     */
    public function testControlCharacterValueThrows(): void {
        $this->expectException(Exception::class);
        $this->expectExceptionCode(100405);
        $this->createCookie()->build('name',new CookieOptions("a\r\nb"));
    }

    /**
     * 测试名称尾随换行被拒绝
     *
     * @return void
     */
    public function testTrailingNewlineNameThrows(): void {
        $this->expectException(Exception::class);
        $this->expectExceptionCode(100405);
        $this->createCookie()->build("name\n",'value');
    }

    /**
     * 测试前缀参与名称校验
     *
     * @return void
     */
    public function testPrefixIsValidated(): void {
        $cookie=$this->createCookie(array('prefix'=>'bad prefix_'));
        $this->expectException(Exception::class);
        $this->expectExceptionCode(100405);
        $cookie->build('name','value');
    }

    /**
     * 测试完整属性透传
     *
     * @return void
     */
    public function testAllAttributesAreMapped(): void {
        $built=$this->createCookie()->build('name',new CookieOptions(
            'value',
            123,
            '/path',
            'example.com',
            true,
            false,
            'Strict'
        ));
        $this->assertSame('value',$built['value']);
        $this->assertSame(123,$built['expire']);
        $this->assertSame('/path',$built['path']);
        $this->assertSame('example.com',$built['domain']);
        $this->assertTrue($built['secure']);
        $this->assertFalse($built['httponly']);
        $this->assertSame('Strict',$built['samesite']);
    }

    /**
     * 测试未知选项键被拒绝
     *
     * @return void
     */
    public function testUnknownOptionThrows(): void {
        $this->expectException(Exception::class);
        $this->expectExceptionCode(100405);
        $this->createCookie()->buildAll(array('name'=>array(
            'value'=>'value',
            'htponly'=>true
        )));
    }

    /**
     * 测试 path / domain 中的非法字符被拒绝
     *
     * @return void
     */
    public function testPathWithInvalidCharactersThrows(): void {
        $this->expectException(Exception::class);
        $this->expectExceptionCode(100405);
        $this->createCookie()->buildAll(array('name'=>array(
            'value'=>'value',
            'path'=>'/my dir/'
        )));
    }

    /**
     * 测试 expire 与 expires 不能同时出现
     *
     * @return void
     */
    public function testExpireAndExpiresConflictThrows(): void {
        $this->expectException(Exception::class);
        $this->expectExceptionCode(100405);
        $this->createCookie()->buildAll(array('name'=>array(
            'value'=>'value',
            'expire'=>60,
            'expires'=>time()+60
        )));
    }

}
