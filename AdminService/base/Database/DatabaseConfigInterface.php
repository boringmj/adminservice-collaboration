<?php

namespace base\Database;

/**
 * 数据库配置契约
 *
 * - 数据库层(契约层)自己不去读全局配置, 只向应用层要三样东西:
 *   连接配置 / 中间件配置 / 中间件实例化
 * - 实现由应用层在引导期安装: `Db::setConfig(new \AdminService\Database\DatabaseConfig())`
 * - 契约层不得引用实现层
 *
 * @access public
 * @package base
 * @version 1.0.0
 */
interface DatabaseConfigInterface {

    /**
     * 取指定连接的配置(缺失时返回空数组)
     *
     * @access public
     * @param string $name 连接名
     * @return array<string,mixed>
     */
    public function connection(string $name): array;

    /**
     * 取中间件配置(类名或实例列表)
     *
     * @access public
     * @return array<mixed>
     */
    public function middlewares(): array;

    /**
     * 把中间件配置项解析为实例(类名经容器装配, 可依赖注入)
     *
     * @access public
     * @param string|object $middleware 中间件类名或实例
     * @return object
     */
    public function resolveMiddleware(string|object $middleware): object;

}
