<?php

namespace AdminService\Router;

use Attribute;

/**
 * 路由声明属性(方法级)
 *
 * - 用于在控制器方法上就近声明路由, 可重复声明多条(即同一处理器的多个路径)
 * - 路径中 `{name}`、`{name:约束}`、`{name?}` 为参数占位符, 可选参数须位于末尾
 * - 分组/前缀能力见 {@see RouteGroup}; 跨控制器能力请走集中式路由文件
 */
#[Attribute(Attribute::TARGET_METHOD|Attribute::IS_REPEATABLE)]
final class Route {

    /**
     * 请求方法
     * @var string
     */
    private string $method;

    /**
     * 路由路径
     * @var string
     */
    private string $path;

    /**
     * 路由名(反向生成 URL 用)
     * @var string|null
     */
    private ?string $name;

    /**
     * 路由级中间件
     * @var array<string|object>
     */
    private array $middlewares;

    /**
     * 构造方法
     *
     * @access public
     * @param string $method 请求方法(传 `*` 表示不限)
     * @param string $path 路由路径
     * @param string|null $name 路由名
     * @param array<string|object> $middleware 路由级中间件
     */
    public function __construct(
        string $method,
        string $path,
        ?string $name=null,
        array $middleware=array()
    ) {
        $this->method=$method;
        $this->path=$path;
        $this->name=$name;
        $this->middlewares=$middleware;
    }

    /**
     * 获取请求方法
     *
     * @access public
     * @return string
     */
    public function getMethod(): string {
        return $this->method;
    }

    /**
     * 获取路由路径
     *
     * @access public
     * @return string
     */
    public function getPath(): string {
        return $this->path;
    }

    /**
     * 获取路由名
     *
     * @access public
     * @return string|null
     */
    public function getName(): ?string {
        return $this->name;
    }

    /**
     * 获取路由级中间件
     *
     * @access public
     * @return array<string|object>
     */
    public function getMiddlewares(): array {
        return $this->middlewares;
    }

}
