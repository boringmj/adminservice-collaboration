<?php

namespace base\Attribute;

use Attribute;

use function is_array;

/**
 * 控制器中间件属性
 *
 * - 类上声明对该控制器的所有方法生效, 方法上声明只对该方法生效
 * - 配置项 `middlewares.controller`、类上、方法上的声明**同级**: 按 `priority` 排序(数值大者更靠外)
 * - 同 `priority` 时按 配置 → 类 → 方法 的收集顺序
 * - 该层位于路由级之**内**(紧贴控制器)
 * - 中间件写法与分组/路由一致: 类名(经容器实例化, 可依赖注入)、已实例化对象或含 `priority` 的条目
 * - 无 `#[Route]` 属性、仅由路由文件指向的控制器同样生效(按处理器类与方法解析)
 */
#[Attribute(Attribute::TARGET_CLASS|Attribute::TARGET_METHOD|Attribute::IS_REPEATABLE)]
final class Middleware {

    /**
     * 中间件
     * @var array<string|object>
     */
    private array $middlewares;

    /**
     * 优先级(数值大者更靠外)
     * @var int
     */
    private int $priority;

    /**
     * 构造方法
     *
     * @access public
     * @param string|array<string|object>|object $middleware 中间件(类名 / 实例 / 数组)
     * @param int $priority 优先级(数值大者更靠外, 默认为0)
     */
    public function __construct(string|array|object $middleware=array(),int $priority=0) {
        $this->middlewares=is_array($middleware)?$middleware:array($middleware);
        $this->priority=$priority;
    }

    /**
     * 获取中间件
     *
     * @access public
     * @return array<string|object>
     */
    public function getMiddlewares(): array {
        return $this->middlewares;
    }

    /**
     * 获取优先级
     *
     * @access public
     * @return int
     */
    public function getPriority(): int {
        return $this->priority;
    }

}
