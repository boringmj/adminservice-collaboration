<?php

namespace AdminService\Database;

use AdminService\Config\Repository;
use base\ConfigInterface;

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
     * 配置(构造期可注入; 未注入时回落门面当前那份 —— 手工构造 / 测试用)
     * @var ConfigInterface|null
     */
    private ?ConfigInterface $config=null;

    /**
     * 取配置契约实例
     *
     * - 容器构建本对象时由构造参数注入(即 `Application::init()` 登记进容器的那一份)
     * - 手工 `new` 时没有注入 → **每次**回落门面当前那份(**不缓存**), 与重构前行为一致;
     *   注入过的那份则保持不变(这就是"配置是快照"的语义)
     *
     * @access private
     * @return ConfigInterface
     */
    private function config(): ConfigInterface {
        return $this->config??Config::repository()??new Repository(array());
    }
    /**
     * 构造方法
     *
     * @access public
     * @param ConfigInterface|null $config 配置契约实例(未注入时回落门面当前那份)
     */
    public function __construct(?ConfigInterface $config=null) {
        $this->config=$config;
    }

    /**
     * 取指定连接的配置(缺失时返回空数组)
     *
     * @access public
     * @param string $name 连接名
     * @return array<string,mixed>
     */
    public function connection(string $name): array {
        $config=$this->config()->get('database.connections.'.$name,array());
        return is_array($config)?$config:array();
    }

    /**
     * 取中间件配置
     *
     * @access public
     * @return array<mixed>
     */
    public function middlewares(): array {
        $config=$this->config()->get('database.middlewares',array());
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
