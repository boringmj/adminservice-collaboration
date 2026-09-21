<?php

namespace AdminService\Router;

use Attribute;

/**
 * 路由声明属性
 *
 * - 用于在控制器方法上就近声明单条路由
 * - 仅支持单条声明, 分组/前缀/命名等跨控制器能力请走集中式路由文件
 */
#[Attribute(Attribute::TARGET_METHOD)]
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
     * 构造方法
     *
     * @access public
     * @param string $method 请求方法(传 `*` 表示不限)
     * @param string $path 路由路径
     */
    public function __construct(string $method,string $path) {
        $this->method=$method;
        $this->path=$path;
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

}
