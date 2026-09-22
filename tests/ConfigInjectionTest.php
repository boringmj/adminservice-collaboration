<?php

namespace Tests;

use PHPUnit\Framework\TestCase;

use Tests\Fixtures\ConfigConsumer;
use Tests\Fixtures\ConfigOnPropertyConflict;
use Tests\Fixtures\ConfigOnSetterConflict;
use Tests\Fixtures\ConfigOnSetterParamConflict;
use Tests\Fixtures\ConfigOnMethodConflict;
use AdminService\App;
use AdminService\ArgumentResolver;
use AdminService\Config;
use AdminService\exception\AutowireException;
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
        // 注解 default:
        $this->assertSame('fallback',$consumer->fallback);
        // 属性自身默认值(非空类型也不会因 null 而 TypeError)
        $this->assertSame('property-default',$consumer->from_property_default);
        // 两者都没有 → null(目标类型须可空)
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
     * 测试方法形参: 框架解析实参时配置注入生效, 且**显式实参优先**
     * @return void
     */
    public function testMethodParamInjected(): void {
        /** @var ConfigConsumer $consumer */
        $consumer=App::make(ConfigConsumer::class);
        // 无实参 → 配置值
        $this->assertSame(Config::get('data.ext_name').'|none',App::exec_class_function($consumer,'methodParamInjected',array()));
        // 有同名实参 → 实参优先, 配置注入不覆盖
        $this->assertSame('explicit-wins|none',App::exec_class_function($consumer,'methodParamInjected',array('ext'=>'explicit-wins')));
        // 顺位实参按位置从左往右填充(这里占用第一个形参, 因此同样覆盖配置注入)
        $this->assertSame('by-position|none',App::exec_class_function($consumer,'methodParamInjected',array('by-position')));
    }

    /**
     * 测试 `exec_function`: 闭包形参上的 `#[Config]` 同样生效
     * @return void
     */
    public function testFunctionParamInjected(): void {
        $result=App::exec_function(
            fn(#[ConfigAttribute('data.ext_name')] string $ext='untouched'): string => $ext,
            array()
        );
        $this->assertSame(Config::get('data.ext_name'),$result);
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

    /**
     * 测试自相矛盾的标注直接报错(fail-fast, 不再静默忽略)
     *
     * - `#[Config]` 与 `#[AutowireProperty]` / `#[AutowireSetter]` / `#[AutowireMethod]` 同挂一处
     * - 此前: 前两者静默忽略 Autowire*, 第三者会把方法**调用两次**
     *
     * @return void
     */
    public function testContradictoryAnnotationsAreRejected(): void {
        foreach(array(
            ConfigOnPropertyConflict::class,
            ConfigOnSetterConflict::class,
            ConfigOnSetterParamConflict::class,
            ConfigOnMethodConflict::class,
        ) as $class) {
            try {
                App::make($class);
                $this->fail($class.' 应当抛出 AutowireException');
            } catch(AutowireException $e) {
                // 四种矛盾的信息措辞不同, 但都必须点明是 #[Config] 的用法冲突
                $this->assertStringContainsString('#[Config]',$e->getMessage());
            }
        }
    }

}
