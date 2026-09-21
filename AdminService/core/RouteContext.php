<?php

namespace AdminService;

use base\RouteContextInterface;

use function lcfirst;
use function preg_match;

/**
 * 路由上下文
 *
 * - 描述"当前正在分发的那条路由": 应用名 / 控制器名 / 方法名 / 控制器类名 / 路径参数
 * - **请求级**对象: 由 `Route` 在分发前构造并登记到请求级容器, 请求结束随容器丢弃
 * - 取代旧的容器全局数据袋(路由上下文此前以数组形式挂在容器的全局数据里), 控制器基类、
 *   视图路径推断与 `App::getAppName()` 系列都改从它取值
 *
 * @access public
 * @package AdminService
 * @version 1.0.0
 */
final class RouteContext implements RouteContextInterface {

    /**
     * 应用名
     * @var string|null
     */
    private ?string $app_name;

    /**
     * 控制器名(不含命名空间)
     * @var string|null
     */
    private ?string $controller_name;

    /**
     * 方法名
     * @var string|null
     */
    private ?string $method_name;

    /**
     * 控制器类名(完整命名空间)
     * @var string|null
     */
    private ?string $controller_class;

    /**
     * 路径参数
     * @var array<string,mixed>
     */
    private array $params;

    /**
     * 构造方法
     *
     * @access public
     * @param string|null $app_name 应用名
     * @param string|null $controller_name 控制器名
     * @param string|null $method_name 方法名
     * @param string|null $controller_class 控制器类名
     * @param array<string,mixed> $params 路径参数
     */
    public function __construct(
        ?string $app_name=null,
        ?string $controller_name=null,
        ?string $method_name=null,
        ?string $controller_class=null,
        array $params=array()
    ) {
        $this->app_name=$app_name;
        $this->controller_name=$controller_name;
        $this->method_name=$method_name;
        $this->controller_class=$controller_class;
        $this->params=$params;
    }

    /**
     * 按处理器与路径参数构造上下文
     *
     * - 应用名与控制器名由处理器类名(`app\{app}\controller\{Controller}`)推断, 推断不出则留空
     * - 只按类名约定推断, 不校验类是否存在
     *
     * @access public
     * @param mixed $handler 处理器(数组形式的 `array(类名或对象, 方法名)`)
     * @param array<string,mixed> $params 路径参数
     * @return static
     */
    public static function fromHandler(mixed $handler,array $params=array()): static {
        $class=is_array($handler)?($handler[0]??null):null;
        $method=is_array($handler)?($handler[1]??null):null;
        $controller_class=is_object($class)?$class::class:(is_string($class)?$class:null);
        $app_name=null;
        $controller_name=null;
        if($controller_class!==null&&preg_match('/^app\\\\([^\\\\]+)\\\\controller\\\\([^\\\\]+)$/',$controller_class,$matches)) {
            $app_name=lcfirst($matches[1]);
            $controller_name=$matches[2];
        }
        return new static(
            $app_name,
            $controller_name,
            is_string($method)?$method:null,
            $controller_class,
            $params
        );
    }

    /**
     * 应用名
     *
     * @access public
     * @return string|null
     */
    public function appName(): ?string {
        return $this->app_name;
    }

    /**
     * 控制器名(不含命名空间)
     *
     * @access public
     * @return string|null
     */
    public function controllerName(): ?string {
        return $this->controller_name;
    }

    /**
     * 方法名
     *
     * @access public
     * @return string|null
     */
    public function methodName(): ?string {
        return $this->method_name;
    }

    /**
     * 控制器类名(完整命名空间)
     *
     * @access public
     * @return string|null
     */
    public function controllerClass(): ?string {
        return $this->controller_class;
    }

    /**
     * 路径参数(路由参数)
     *
     * @access public
     * @return array<string,mixed>
     */
    public function params(): array {
        return $this->params;
    }

}
