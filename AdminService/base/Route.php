<?php

namespace base;

use AdminService\App;
use AdminService\Exception;
use ReflectionException;

/**
 * 路由抽象基类
 *
 * - 持有请求对象并提供「可运行」契约, 供自定义路由类继承
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
     * @throws Exception
     * @throws ReflectionException
     */
    final public function __construct(?Request $request=null) {
        $this->request=$request??App::get(Request::class);
    }

    /**
     * 开始运行
     *
     * @access public
     * @return void
     */
    abstract public function run(): void;

}
