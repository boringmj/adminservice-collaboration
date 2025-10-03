<?php

namespace Tests;

use PHPUnit\Framework\TestCase;

use Tests\Fixtures\UserId;
use Tests\Fixtures\UserInfo;
use Tests\Fixtures\UserName;
use Tests\Fixtures\UserStatus;
use Tests\Fixtures\AbstractStatus;
use AdminService\App;
use AdminService\Config;
use AdminService\DynamicProxy;

class AutowirePropertyTest extends TestCase {

    /**
     * 类初始化前执行
     * @return void
     */
    public static function setUpBeforeClass(): void {
        Config::load();
    }

    /**
     * 测试自动注入属性
     * @return void
     */
    public function testAutowireProperty(): void {
        App::bind(AbstractStatus::class, UserStatus::class);
        $userInfo=App::make(UserInfo::class);
        // 断言无参注入结果
        $this->assertInstanceOf(UserName::class, $userInfo->name);
        // 断言抽象类注入结果
        $this->assertInstanceOf(AbstractStatus::class, $userInfo->status);
        // 断言代理对象注入结果
        $this->assertInstanceOf(DynamicProxy::class, $userInfo->id);
        $id_object=$userInfo->id->instance();
        $this->assertInstanceOf(UserId::class, $id_object);
        // 断言id属性值
        $this->assertEquals(1, $id_object->id);
    }

}