<?php

namespace base\Attribute;

use Attribute;

use function func_num_args;

/**
 * 配置项注入
 *
 * - 把**配置项的值**(而不是配置对象)注入到属性 / Setter 形参 / 构造函数形参
 * - 键为点分路径(如 `app.path`、`data.path`), 可给默认值;标量按容器既有规则静默转换
 * - 三种写法:
 *   ① 属性: `#[Config('app.path')] private string $path;`
 *   ② Setter 方法: `#[Config('log.path')] public function setLogPath(string $path): void {}`(方法唯一的形参收到该值)
 *   ③ 构造函数形参: `public function __construct(#[Config('data.path')] string $path='') {}`
 * - **不支持控制器方法形参**(控制器形参只注入路由参数, 见路由模块约定)
 * - 未提供 `default` 且配置缺失时, 值为 `null`(形参有默认值时以形参默认值兜底)
 *
 * @access public
 * @package base\Attribute
 * @version 1.0.0
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_METHOD | Attribute::TARGET_PARAMETER)]
final class Config {

    /**
     * 配置键(点分路径)
     * @var string
     */
    private string $key;

    /**
     * 默认值
     * @var mixed
     */
    private mixed $default=null;

    /**
     * 是否显式提供了默认值
     * @var bool
     */
    private bool $has_default=false;

    /**
     * 构造方法
     *
     * @access public
     * @param string $key 配置键(点分路径)
     * @param mixed $default 默认值(可省略)
     */
    public function __construct(string $key,mixed $default=null) {
        $this->key=$key;
        $this->has_default=func_num_args()>1;
        $this->default=$default;
    }

    /**
     * 获取配置键
     *
     * @access public
     * @return string
     */
    public function getKey(): string {
        return $this->key;
    }

    /**
     * 获取默认值
     *
     * @access public
     * @return mixed
     */
    public function getDefault(): mixed {
        return $this->default;
    }

    /**
     * 是否显式提供了默认值
     *
     * - 用于区分"没写默认值"与"默认值就是 null"
     *
     * @access public
     * @return bool
     */
    public function hasDefault(): bool {
        return $this->has_default;
    }

}
