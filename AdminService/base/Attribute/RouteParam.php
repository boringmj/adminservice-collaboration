<?php

namespace base\Attribute;

use Attribute;

/**
 * 路由参数绑定属性(参数级)
 *
 * - 声明当前形参绑定到哪个路径占位符
 * - 未声明时按形参名与占位符名匹配
 * - 同一形参不要叠加 `#[Config]`: 路由绑定提供实参时 `#[Config]` 不会生效
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
final class RouteParam {

    /**
     * 路径占位符名称
     * @var string
     */
    private string $name;

    /**
     * 构造方法
     *
     * @access public
     * @param string $name 路径占位符名称
     */
    public function __construct(string $name) {
        $this->name=$name;
    }

    /**
     * 获取路径占位符名称
     *
     * @access public
     * @return string
     */
    public function getName(): string {
        return $this->name;
    }

}
