<?php

namespace Tests\Fixtures;

use base\Attribute\Config;

/**
 * 配置项注入用例: 属性 / Setter / 构造形参三处
 *
 * @package Tests\Fixtures
 */
class ConfigConsumer {

    /**
     * 属性注入(点分键)
     * @var string
     */
    #[Config('data.ext_name')]
    public string $ext_name='';

    /**
     * 属性注入(带默认值; 键不存在时用默认值)
     * @var string
     */
    #[Config('not.exist.key',default:'fallback')]
    public string $fallback='';

    /**
     * 属性注入(键缺失时的取值顺序: 注解 default: > **属性自身默认值** > null)
     * @var string
     */
    #[Config('not.exist.key')]
    public string $from_property_default='property-default';

    /**
     * 属性注入(既无注解默认值、属性也没有默认值 → null)
     * @var string|null
     */
    #[Config('not.exist.key')]
    public ?string $missing;

    /**
     * 标量转换: 配置里是 int, 目标类型是 string
     * @var string
     */
    #[Config('data.dir_mode')]
    public string $dir_mode_as_string='';

    /**
     * Setter 注入(方法唯一的形参收到配置值)
     * @var string
     */
    public string $log_path='';

    /**
     * 构造形参注入
     * @var int
     */
    public int $dir_mode;

    /**
     * 构造方法
     *
     * @param int $dir_mode 目录权限(由配置注入)
     */
    public function __construct(
        #[Config('data.dir_mode',default:0)] int $dir_mode=0
    ) {
        $this->dir_mode=$dir_mode;
    }

    /**
     * Setter: 由配置注入日志目录
     *
     * @param string $path 日志目录
     * @return void
     */
    #[Config('log.path')]
    public function setLogPath(string $path): void {
        $this->log_path=$path;
    }

    /**
     * 普通方法形参: 由框架解析实参时(`exec_class_function` / `exec_function`)同样生效
     *
     * - 校验点: ①配置注入生效 ②**显式实参优先于配置注入**(同名实参时不看配置)
     *
     * @param string $ext 扩展名(由配置注入)
     * @param string $explicit 显式实参
     * @return string
     */
    public function methodParamInjected(#[Config('data.ext_name')] string $ext='untouched',string $explicit='none'): string {
        return $ext.'|'.$explicit;
    }

}
