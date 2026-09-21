<?php

namespace Tests;

use PHPUnit\Framework\TestCase;

use Tests\Fixtures\ConfigConsumer;
use AdminService\App;
use AdminService\ArgumentResolver;
use AdminService\Config;
use base\Attribute\Config as ConfigAttribute;

/**
 * 配置项注入用例(`#[Config]`)
 *
 * - 覆盖三处 target: 属性 / Setter 方法 / 构造函数形参
 * - 覆盖点分键、注解默认值、形参默认值兜底、标量转换
 * - 覆盖边界: 控制器方法形参**不**注入配置(控制器形参只注入路由参数)
 */
class ConfigInjectionTest extends TestCase {

    /**
     * 类初始化前执行
     * @return void
     */
    public static function setUpBeforeClass(): void {
        load_test_config();
    }

    /**
     * 测试属性注入(点分键 + 标量转换)
     * @return void
     */
    public function testPropertyInjection(): void {
        /** @var ConfigConsumer $consumer */
        $consumer=App::make(ConfigConsumer::class);
        $this->assertSame(Config::get('data.ext_name'),$consumer->ext_name);
        // 配置里是 int, 目标是 string: 按既有标量规则转换
        $this->assertSame((string)Config::get('data.name_cycle'),$consumer->cycle_as_string);
    }

    /**
     * 测试注解默认值与"无默认值时为空"
     * @return void
     */
    public function testDefaults(): void {
        /** @var ConfigConsumer $consumer */
        $consumer=App::make(ConfigConsumer::class);
        $this->assertSame('fallback',$consumer->fallback);
        $this->assertNull($consumer->missing);
    }

    /**
     * 测试 Setter 注入(方法唯一的形参收到配置值)
     * @return void
     */
    public function testSetterInjection(): void {
        /** @var ConfigConsumer $consumer */
        $consumer=App::make(ConfigConsumer::class);
        $this->assertSame(Config::get('log.path'),$consumer->log_path);
    }

    /**
     * 测试构造函数形参注入
     * @return void
     */
    public function testConstructorParamInjection(): void {
        /** @var ConfigConsumer $consumer */
        $consumer=App::make(ConfigConsumer::class);
        $this->assertSame((int)Config::get('data.name_cycle'),$consumer->cycle);
    }

    /**
     * 测试控制器方法形参不注入配置
     * @return void
     */
    public function testMethodParamNotInjected(): void {
        /** @var ConfigConsumer $consumer */
        $consumer=App::make(ConfigConsumer::class);
        $this->assertSame('untouched',App::exec_class_function($consumer,'methodParamNotInjected',array()));
    }

    /**
     * 测试未回填取值回调时的行为(组件可脱离容器/配置单独使用)
     * @return void
     */
    public function testConfigValueWithoutValueResolver(): void {
        $resolver=new ArgumentResolver();
        $this->assertSame('fallback',$resolver->configValue(new ConfigAttribute('not.exist.key',default:'fallback')));
        // 标量转换不依赖取值回调
        $this->assertSame('1',$resolver->configValue(new ConfigAttribute('any.key',default:true),array('string')));
    }

}
