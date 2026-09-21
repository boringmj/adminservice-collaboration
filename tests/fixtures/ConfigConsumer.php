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
     * 属性注入(无默认值; 键不存在时为 null)
     * @var string|null
     */
    #[Config('not.exist.key')]
    public ?string $missing='sentinel';

    /**
     * 标量转换: 配置里是 int, 目标类型是 string
     * @var string
     */
    #[Config('data.name_cycle')]
    public string $cycle_as_string='';

    /**
     * Setter 注入(方法唯一的形参收到配置值)
     * @var string
     */
    public string $log_path='';

    /**
     * 构造形参注入
     * @var int
     */
    public int $cycle;

    /**
     * 构造方法
     *
     * @param int $cycle 周期(由配置注入)
     */
    public function __construct(
        #[Config('data.name_cycle',default:0)] int $cycle=0
    ) {
        $this->cycle=$cycle;
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
     * 反例: **控制器方法形参不注入配置**(控制器形参只注入路由参数)
     *
     * @param string $ext 扩展名
     * @return string
     */
    public function methodParamNotInjected(#[Config('data.ext_name')] string $ext='untouched'): string {
        return $ext;
    }

}
