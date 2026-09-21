<?php

namespace AdminService\Database;

use base\Database\DatabaseConfigInterface;
use AdminService\App;
use AdminService\Config;

use function is_array;

/**
 * 数据库配置(契约 `base\Database\DatabaseConfigInterface` 的框架实现)
 *
 * - 读 `config/database.php` 的 `database.connections.<连接名>` 与 `database.middlewares`
 * - 中间件类名经容器解析(`App::get`), 因此中间件可以依赖注入
 *
 * @access public
 * @package AdminService
 * @version 1.0.0
 */
final class DatabaseConfig implements DatabaseConfigInterface {

    /**
     * 取指定连接的配置(缺失时返回空数组)
     *
     * @access public
     * @param string $name 连接名
     * @return array<string,mixed>
     */
    public function connection(string $name): array {
        $config=Config::get('database.connections.'.$name,array());
        return is_array($config)?$config:array();
    }

    /**
     * 取中间件配置
     *
     * @access public
     * @return array<mixed>
     */
    public function middlewares(): array {
        $config=Config::get('database.middlewares',array());
        return is_array($config)?$config:array();
    }

    /**
     * 把中间件配置项解析为实例(类名经容器装配, 可依赖注入)
     *
     * @access public
     * @param string|object $middleware 中间件类名或实例
     * @return object
     * @throws \AdminService\Exception
     */
    public function resolveMiddleware(string|object $middleware): object {
        if(!is_string($middleware))
            return $middleware;
        return App::get($middleware);
    }

}
