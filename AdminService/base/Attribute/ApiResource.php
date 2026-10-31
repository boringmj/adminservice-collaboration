<?php

namespace base\Attribute;

use Attribute;

/**
 * RESTful API 资源路由声明(控制器级)
 *
 * - 等价于在路由文件中调用 {@see \AdminService\Router\Router::apiResource()}
 * - 声明在控制器类上, 生成 index/store/show/update/destroy 五个动作, 不含 create/edit 表单页
 * - 与 `#[RouteGroup]` 组合时, 分组前缀会与资源路径拼接
 * - `{id}` 占位符与路由名规则见 {@see \AdminService\Router\Router::apiResource()}
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class ApiResource {

    /**
     * 资源根路径
     * @var string
     */
    private string $path;

    /**
     * 构造方法
     *
     * @access public
     * @param string $path 资源根路径(如 `/users`)
     */
    public function __construct(string $path) {
        $this->path=$path;
    }

    /**
     * 获取资源根路径
     *
     * @access public
     * @return string
     */
    public function getPath(): string {
        return $this->path;
    }

}
