<?php

namespace Tests\Fixtures;

use base\Controller;
use base\Attribute\Config;

use function is_string;

/**
 * 控制器基类兼容性用例: 子类使用了与基类内部管道**同名**的属性
 *
 * - 基类的 `$config` / `$container` 是私有内部管道, 子类同名属性应互不冲突
 * - 回归防线: 若基类把它们改回 `protected`, 本文件会直接触发
 *   `Access level to ...::$config must be protected ... or weaker`
 *
 * @package Tests\Fixtures
 */
class ConfigNamedController extends Controller {

    /**
     * 子类自己的 `$config`(注入配置项的值, 与基类的配置契约同名但互不干扰)
     * @var string
     */
    #[Config('data.ext_name')]
    private string $config='';

    /**
     * 子类自己的 `$container`(纯占位, 用于验证同名不冲突)
     * @var string
     */
    private $container='own-container';

    /**
     * 读取子类自己的 `$config`
     *
     * @return string
     */
    public function ownConfig(): string {
        return $this->config;
    }

    /**
     * 读取子类自己的 `$container`
     *
     * @return string
     */
    public function ownContainer(): string {
        return is_string($this->container)?$this->container:'object';
    }

    /**
     * 触发基类 `view()` 里"缺配置契约"的分支(用于验证抛的是具体异常)
     *
     * @return string
     */
    public function renderSomething(): string {
        return $this->view('whatever');
    }

}
