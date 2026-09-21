<?php

namespace AdminService\Router;

use Attribute;

/**
 * 路由分组属性(控制器级)
 *
 * - 声明在控制器类上, 为其下所有 `#[Route]` 方法提供统一路径前缀与中间件
 * - 方法路径为**子路径**: 类声明 `/index`、方法声明 `/{name?}` 时完整路径为 `/index/{name?}`
 * - 与 {@see Router::group()} 的分组语义一致(前缀 + 中间件)
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class RouteGroup {

    /**
     * 路径前缀
     * @var string
     */
    private string $prefix;

    /**
     * 分组中间件
     * @var array<string|object>
     */
    private array $middlewares;

    /**
     * 构造方法
     *
     * @access public
     * @param string $prefix 路径前缀(如 `/index`)
     * @param array<string|object> $middleware 分组中间件
     */
    public function __construct(string $prefix='',array $middleware=array()) {
        $this->prefix=$prefix;
        $this->middlewares=$middleware;
    }

    /**
     * 获取路径前缀
     *
     * @access public
     * @return string
     */
    public function getPrefix(): string {
        return $this->prefix;
    }

    /**
     * 获取分组中间件
     *
     * @access public
     * @return array<string|object>
     */
    public function getMiddlewares(): array {
        return $this->middlewares;
    }

}
