<?php

namespace Tests;

use PHPUnit\Framework\TestCase;

use base\AbstractSession;
use AdminService\App;
use AdminService\NativeSession;

/**
 * 会话服务测试
 *
 * - 会话已从请求中拆出为独立服务, 经容器取用
 * - 会话开启(`init()`)依赖 SAPI 状态, 由真实请求冒烟覆盖, 此处只覆盖数据操作与容器解析
 */
class SessionTest extends TestCase {

    /**
     * 类初始化前执行
     * @return void
     */
    public static function setUpBeforeClass(): void {
        App::init();
    }

    /**
     * 每个测试后清理会话数据
     * @return void
     */
    protected function tearDown(): void {
        $_SESSION=array();
    }

    /**
     * 测试契约名解析到配置的实现类
     * @return void
     */
    public function testContractResolvesToConfiguredClass(): void {
        $session=new NativeSession();
        App::set(AbstractSession::class,$session);
        $this->assertInstanceOf(NativeSession::class,App::get(AbstractSession::class));
        $this->assertSame($session,App::get(AbstractSession::class));
    }

    /**
     * 测试会话数据的读写与删除
     * @return void
     */
    public function testDataRoundTrip(): void {
        $session=new NativeSession();
        $session->set('name','value');
        $this->assertSame('value',$session->get('name'));
        // 数组形式批量写入
        $session->set(array('a'=>'1','b'=>'2'),'');
        $this->assertSame('1',$session->get('a'));
        $this->assertSame('2',$session->get('b'));
        // 缺省值
        $this->assertSame('fallback',$session->get('missing','fallback'));
        // 删除
        $session->delete('a');
        $this->assertNull($session->get('a'));
        $session->delete(array('b','name'));
        $this->assertNull($session->get('b'));
        $this->assertNull($session->get('name'));
    }

    /**
     * 测试清空会话数据
     * @return void
     */
    public function testClear(): void {
        $session=new NativeSession();
        $session->set('name','value');
        $session->clear();
        $this->assertNull($session->get('name'));
        $this->assertSame(array(),$_SESSION);
    }

    /**
     * 测试会话ID: 未显式设置时取当前会话ID
     * @return void
     */
    public function testSessionId(): void {
        $session=new NativeSession();
        $this->assertSame(session_id(),$session->getId());
        $session->setId('fixed-id');
        $this->assertSame('fixed-id',$session->getId());
    }

}
