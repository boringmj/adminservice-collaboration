<?php

namespace Tests;

use PHPUnit\Framework\TestCase;

use AdminService\Config\Repository;
use AdminService\HttpRequest;
use AdminService\Response;
use base\CookieOptions;

use function time;

/**
 * 响应导出测试
 *
 * - 非 SAPI 入口导出时必须套用 Cookie 服务的前缀与默认属性
 */
class ResponseExportTest extends TestCase {

    /**
     * 测试: 导出 Cookie 带前缀与配置默认属性
     *
     * @return void
     */
    public function testExportAppliesCookieConfig(): void {
        $config=new Repository(array(
            'cookie'=>array(
                'prefix'=>'pre_',
                'expire'=>120,
                'path'=>'/app',
                'domain'=>'',
                'secure'=>true,
                'httponly'=>true
            )
        ));
        $response=new Response($config);
        $response->contentType('text/plain');
        $response->cookie('name','v');
        $response->cookie('custom',new CookieOptions('x',time()+10,'/raw','',false,false));

        $data=$response->export(new HttpRequest(array('server'=>array(
            'REQUEST_URI'=>'/',
            'REQUEST_METHOD'=>'GET'
        ))));
        $cookies=$data['cookies'];
        $this->assertArrayHasKey('pre_name',$cookies);
        $this->assertArrayHasKey('pre_custom',$cookies);

        $name=$cookies['pre_name'];
        $this->assertSame('v',$name['value']);
        $this->assertSame('/app',$name['path']);
        $this->assertSame('',$name['domain']);
        $this->assertTrue($name['secure']);
        $this->assertTrue($name['httponly']);
        $this->assertGreaterThanOrEqual(time()+119,$name['expire']);
        $this->assertLessThanOrEqual(time()+121,$name['expire']);

        $custom=$cookies['pre_custom'];
        $this->assertSame('x',$custom['value']);
        $this->assertSame('/raw',$custom['path']);
        $this->assertFalse($custom['secure']);
        $this->assertFalse($custom['httponly']);
        $this->assertGreaterThanOrEqual(time()+9,$custom['expire']);
        $this->assertLessThanOrEqual(time()+11,$custom['expire']);
    }

}
