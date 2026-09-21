<?php

namespace base;

use ReflectionException;

/**
 * 路由抽象基类
 *
 * - 持有请求对象并提供「可运行」契约, 供自定义路由类继承
 * - 请求对象未显式传入时经**容器契约**解析(契约层不引用实现层, 也不再依赖门面)
 */
abstract class Route {

    /**
     * 请求对象
     * @var Request
     */
    protected Request $request;

    /**
     * 构造方法
     *
     * @access public
     * @param Request|null $request 请求对象(为空时经容器解析)
     * @param Container|null $container 容器契约(解析请求对象用)
     * @throws Exception 两处都未提供时抛出(提示改用容器构建)
     * @throws ReflectionException
     */
    final public function __construct(?Request $request=null,?Container $container=null) {
        if($request===null) {
            if($container===null)
                throw new Exception('路由需要请求对象: 请传入 $request, 或由容器构建(注入 base\Container 契约)');
            $request=$container->get(Request::class);
        }
        $this->request=$request;
    }

    /**
     * 开始运行
     *
     * @access public
     * @return void
     */
    abstract public function run(): void;

}
