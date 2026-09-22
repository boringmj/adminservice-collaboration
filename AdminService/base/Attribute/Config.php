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
 *   - 属性: `#[Config('app.path')] private string $path;`
 *   - Setter 方法: `#[Config('log.path')] public function setLogPath(string $path): void {}`(方法唯一的形参收到该值)
 *   - 构造函数形参: `public function __construct(#[Config('data.path')] string $path='') {}`
 * - **生效条件(一句话)**: 凡是由框架解析实参的地方, 显式标注的 `#[Config]` 都会生效 —— 包括
 *   构造函数形参、`#[AutowireMethod]` 方法的形参, 以及经 `App::exec_class_function()` / `exec_function()`
 *   调用的方法/函数形参(控制器由框架经前者调用, 因此控制器方法形参上的标注同样生效)。
 *   框架不参与解析的地方(你直接 `$obj->method()`)自然不生效
 * - **实参优先**: 具名实参 / 顺位实参 > `#[Config]` > 按类型注入 —— 例如路由参数与配置键同名时, 路由参数优先
 *   (因此"控制器形参只注入路由参数"的既有语义不变: **未标注**的形参不会拿到配置值)
 * - **不能与 `#[AutowireProperty]` / `#[AutowireSetter]` / `#[AutowireMethod]` 同挂一处**:
 *   前者是"注入配置值", 后者是"注入服务对象 / 生命周期钩子", 语义冲突, 同挂会抛 `AutowireException`;
 *   `#[AutowireSetter]` **方法的形参上**标 `#[Config]` 也会报错(那条路径按类型注入服务对象, 不会应用它)
 * - 键缺失时的兜底顺序: 注解 `default:` > **目标自身的默认值**(属性默认值 / 形参默认值) > `null`;
 *   取到 `null` 而目标类型不可空时会抛 `TypeError`, 请显式给 `default:` 或把类型写成可空
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
