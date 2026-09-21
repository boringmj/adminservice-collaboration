<?php

namespace AdminService;

use base\Request;

use function array_reverse;
use function is_object;

/**
 * 中间件管道
 *
 * - 按声明顺序包裹执行, 最先声明的中间件在最外层
 * - 中间件经容器实例化, 支持构造函数依赖注入; 容器中已登记实例时直接取用
 * - 只负责执行, 不关心中间件来源与层间顺序(请求 / 分组 / 路由 / 控制器由调用方组装)
 * - 请求对象显式传入, 缺省取容器中的当前请求
 */
final class Pipeline {

    /**
     * 中间件列表
     * @var array<string|object>
     */
    private array $middlewares;

    /**
     * 请求对象
     * @var Request
     */
    private Request $request;

    /**
     * 构造方法
     *
     * @access public
     * @param array<string|object> $middlewares 中间件列表(类名或实例)
     * @param Request|null $request 请求对象(null 时取容器中的当前请求)
     */
    public function __construct(array $middlewares=array(),?Request $request=null) {
        $this->middlewares=$middlewares;
        $this->request=$request??App::get(Request::class);
    }

    /**
     * 执行管道
     *
     * - 处理器返回值由核心逻辑自行处置(如写入响应对象), 不经管道回传
     *
     * @access public
     * @param callable $core 核心逻辑
     * @return void
     */
    public function then(callable $core): void {
        $next=$core;
        foreach(array_reverse($this->middlewares) as $middleware)
            $next=$this->wrap($middleware,$next);
        $next();
    }

    /**
     * 将单个中间件包裹到下一层处理器外层
     *
     * - 参数按名注入, 与 {@see \AdminService\App::exec_class_function} 约定一致
     *
     * @access private
     * @param string|object $middleware 中间件(类名或实例)
     * @param callable $next 下一层处理器
     * @return callable
     */
    private function wrap(string|object $middleware,callable $next): callable {
        return function() use ($middleware,$next): void {
            $instance=is_object($middleware)?$middleware:App::get($middleware);
            App::exec_class_function($instance,'handle',array(
                'request'=>$this->request,
                'next'=>$next
            ));
        };
    }

}
