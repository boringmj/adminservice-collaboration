<?php

namespace base\Attribute\RouteMethod;

use Attribute;
use base\Attribute\Method;
use base\Attribute\Route;

/**
 * POST 路由注解(方法级)
 *
 * - `#[Route(Method::POST, $path, ...)]` 的短语糖, 继承 `Route`
 * - 构造参数除请求方法外与 `#[Route]` 一致, 可重复声明
 */
#[Attribute(Attribute::TARGET_METHOD|Attribute::IS_REPEATABLE)]
final class Post extends Route {

    /**
     * 构造方法
     *
     * @access public
     * @param string $path 路由路径
     * @param string|null $name 路由名
     * @param string|array<string|object>|object $middleware 路由级中间件(类名 / 实例 / 数组)
     * @param array<string,string> $bind 路由参数到控制器形参的绑定(占位符名 => 形参名)
     */
    public function __construct(
        string $path,
        ?string $name=null,
        string|array|object $middleware=array(),
        array $bind=array()
    ) {
        parent::__construct(Method::POST,$path,$name,$middleware,$bind);
    }

}
