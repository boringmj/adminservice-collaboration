<?php

namespace base\Attribute;

use Attribute;

/**
 * RESTful API 资源路由声明(控制器级)
 *
 * - 等价于在路由文件中调用 {@see \AdminService\Router\Router::apiResource()}
 * - 默认按控制器已实现的 public 标准动作自动裁剪; `$only` / `$except` 可显式裁剪
 * - `$only` 与 `$except` 不能同时非空; 过滤后的动作按标准顺序注册
 * - 标准动作不含 create/edit 表单页
 * - 同一控制器可重复声明多个资源根, 按类上的声明顺序注册
 * - 类级注解不随继承传递; 带资源声明的控制器必须可实例化
 * - 与 `#[RouteGroup]` 组合时, 分组前缀会与资源路径拼接
 * - `{id}` 占位符与路由名规则见 {@see \AdminService\Router\Router::apiResource()}
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final class ApiResource {

    /**
     * 资源根路径
     * @var string
     */
    private string $path;

    /**
     * 仅注册的动作
     * @var array<string>
     */
    private array $only;

    /**
     * 从全量标准动作中排除的动作
     * @var array<string>
     */
    private array $except;

    /**
     * 构造方法
     *
     * @access public
     * @param string $path 资源根路径(如 `/files`)
     * @param array<string> $only 仅注册的动作(白名单)
     * @param array<string> $except 从全量标准动作中排除的动作
     */
    public function __construct(string $path,array $only=array(),array $except=array()) {
        $this->path=$path;
        $this->only=$only;
        $this->except=$except;
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

    /**
     * 获取仅注册的动作
     *
     * @access public
     * @return array<string>
     */
    public function getOnly(): array {
        return $this->only;
    }

    /**
     * 获取排除的动作
     *
     * @access public
     * @return array<string>
     */
    public function getExcept(): array {
        return $this->except;
    }

}
