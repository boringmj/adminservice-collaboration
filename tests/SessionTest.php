<?php

namespace Tests;

use PHPUnit\Framework\TestCase;

use base\AbstractSession;
use AdminService\App;
use AdminService\ArraySession;
use AdminService\Config;
use AdminService\Exception;
use AdminService\NativeSession;

/**
 * 会话服务测试
 *
 * - 会话为独立服务, 经容器取用; 未开启原生会话时使用内存驱动
 * - 原生会话的开启(`init()`)依赖 SAPI 状态, 由真实请求冒烟覆盖
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
     * 每个测试后清理会话数据与配置
     * @return void
     */
    protected function tearDown(): void {
        $_SESSION=array();
        Config::load();
    }

    /**
     * 测试契约名解析到已注册的驱动实例
     * @return void
     */
    public function testContractResolvesToRegisteredDriver(): void {
        $session=new ArraySession();
        App::instance(AbstractSession::class,$session);
        $this->assertSame($session,App::get(AbstractSession::class));
    }

    /**
     * 测试默认驱动与开启开关
     *
     * - 默认内存驱动 + 不开启, 因此零配置下不会下发会话Cookie
     *
     * @return void
     */
    public function testDefaultDriver(): void {
        $this->assertSame(ArraySession::class,Config::get('session.class'));
        $this->assertFalse(Config::get('session.start'));
    }

    /**
     * 测试内存驱动的数据读写与删除
     * @return void
     */
    public function testArraySessionDataRoundTrip(): void {
        $session=new ArraySession();
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
     * 测试内存驱动不会写入超全局
     *
     * - 这是"假会话"缺陷的防线: 数据只在实例内, 不落盘也不污染 `$_SESSION`
     *
     * @return void
     */
    public function testArraySessionDoesNotTouchSuperglobal(): void {
        $session=new ArraySession();
        $session->set('name','value');
        $this->assertSame(array(),$_SESSION);
        $session->clear();
        $this->assertNull($session->get('name'));
    }

    /**
     * 测试原生会话未开启时读写报错
     *
     * - 避免"看似写入成功实则丢失"
     *
     * @return void
     */
    public function testNativeSessionRequiresStart(): void {
        $session=new NativeSession();
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('会话未开启');
        $session->set('name','value');
    }

    /**
     * 测试会话ID: 未显式设置时取当前会话ID
     * @return void
     */
    public function testSessionId(): void {
        $session=new ArraySession();
        $this->assertSame('',$session->getId());
        $session->setId('fixed-id');
        $this->assertSame('fixed-id',$session->getId());
    }

}
