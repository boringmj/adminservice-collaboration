<?php

namespace AdminService;

use base\Request;

use function array_key_exists;
use function array_merge;
use function array_reverse;
use function is_array;
use function is_object;
use function usort;

/**
 * 中间件管道
 *
 * - 按声明顺序包裹执行, 最先声明的中间件在最外层
 * - 中间件经容器实例化, 支持构造函数依赖注入; 容器中已登记实例时直接取用
 * - 只负责执行与层内排序, 不关心层间顺序(请求 / 分组 / 路由 / 控制器由调用方组装)
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
     * 归一化中间件声明
     *
     * - 接受 类名 / 实例 / 数组 三种写法, 数组为「条目列表」
     * - 条目写法用于声明层内优先级: `array('middleware'=>类名或实例, 'priority'=>10)`
     * - 含 `middleware` 键的数组视为**单条条目**, 其余数组视为条目列表
     *
     * @access public
     * @param mixed $middleware 中间件声明
     * @return array<array{middleware:string|object,priority:int}> 归一化条目
     */
    public static function normalize(mixed $middleware): array {
        if($middleware===null||$middleware===array())
            return array();
        // 类名或实例: 默认优先级
        if(is_string($middleware)||is_object($middleware))
            return array(array('middleware'=>$middleware,'priority'=>0));
        if(!is_array($middleware))
            return array();
        // 单条带优先级的条目: `middleware` 亦可为列表, 共用该条目的优先级
        if(array_key_exists('middleware',$middleware)) {
            $priority=(int)($middleware['priority']??0);
            $entries=array();
            foreach(self::normalize($middleware['middleware']) as $entry)
                $entries[]=array('middleware'=>$entry['middleware'],'priority'=>$priority);
            return $entries;
        }
        // 条目列表
        $entries=array();
        foreach($middleware as $item)
            $entries=array_merge($entries,self::normalize($item));
        return $entries;
    }

    /**
     * 层内排序并展开为可执行列表
     *
     * - `priority` 大者靠外(先执行); 同 `priority` 保持声明顺序
     * - 排序只作用于同一层: 层与层的先后由调用方组装决定
     *
     * @access public
     * @param array<array{middleware:string|object,priority:int}> $entries 归一化条目
     * @return array<string|object> 中间件列表(由外向内)
     */
    public static function order(array $entries): array {
        // usort 为稳定排序: 同优先级保持传入顺序
        usort($entries,static function(array $a,array $b): int {
            return $b['priority']<=>$a['priority'];
        });
        $middlewares=array();
        foreach($entries as $entry)
            $middlewares[]=$entry['middleware'];
        return $middlewares;
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
