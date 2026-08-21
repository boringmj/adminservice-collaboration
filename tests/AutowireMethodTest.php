<?php

namespace Tests;

use PHPUnit\Framework\TestCase;

use Tests\Fixtures\AbstractStatus;
use Tests\Fixtures\UserName;
use Tests\Fixtures\UserProfile;
use Tests\Fixtures\UserStatus;
use AdminService\App;
use AdminService\Config;

/**
 * 自动方法注入(生命周期)测试
 *
 * - 验证 #[AutowireMethod] 标记的方法在构建后自动调用, 参数按类型注入
 */
class AutowireMethodTest extends TestCase {

    /**
     * 类初始化前执行
     * @return void
     */
    public static function setUpBeforeClass(): void {
        Config::load();
    }

    /**
     * 测试生命周期方法注入
     * @return void
     */
    public function testAutowireMethod(): void {
        App::bind(AbstractStatus::class,UserStatus::class);
        $profile=App::make(UserProfile::class);
        // 生命周期方法被自动调用
        $this->assertTrue($profile->booted);
        // 参数按类型注入
        $this->assertInstanceOf(UserName::class,$profile->name);
        $this->assertInstanceOf(AbstractStatus::class,$profile->status);
    }

}
