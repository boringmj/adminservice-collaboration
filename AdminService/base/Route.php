<?php

namespace base;

use AdminService\Exception;
use AdminService\App;
use ReflectionException;

use function array_shift;
use function array_values;
use function count;
use function explode;
use function implode;
use function preg_match;
use function preg_replace;

/**
 * 路由抽象基类
 *
 * - 负责请求路径的解析与初始化, 并提供「可加载 / 可运行」契约
 * - 请求路径经 {@see Request} 抽象获取, 不直接读取超全局
 */
abstract class Route {

    /**
     * 请求对象
     * @var Request
     */
    protected Request $request;

    /**
     * 路由路径组
     * @var array
     */
    protected array $uri;

    /**
     * 是否已经初始化
     * @var bool
     */
    protected bool $is_init;

    /**
     * 加载路由
     *
     * @access public
     * @param array<string,mixed> $route_info 路由路径组
     * @return self
     */
    abstract public function load(array $route_info=array()): self;

     /**
     * 开始运行控制器(如果没有加载路由则会自动加载)
     *
     * @access public
     * @return void
     * @throws Exception|ReflectionException
     */
    abstract public function run(): void;

    /**
     * 构造方法(如果都传入则默认初始化)
     *
     * @access public
     * @param Request|null $request 请求对象
     * @throws Exception
     * @throws ReflectionException
     */
    final public function __construct(?Request $request=null) {
        $this->is_init=false;
        $this->uri=array();
        $this->init($request);
    }

    /**
     * 初始化路由
     *
     * @access public
     * @param Request|null $request 请求对象
     * @return self
     * @throws Exception
     * @throws ReflectionException
     */
    final public function init(?Request $request=null): self {
        $this->request=$request??App::get(Request::class);
        $this->uri=$this->parseUri((string)$this->request->getServer('REQUEST_URI',''));
        $this->is_init=true;
        return $this;
    }

    /**
     * 获取路由路径组
     *
     * @access public
     * @return array
     * @throws Exception
     */
    final public function get(): array {
        $this->checkInit();
        return $this->uri;
    }

    /**
     * 检查是否已经初始化
     *
     * @access protected
     * @return void
     * @throws Exception
     */
    protected function checkInit(): void {
        if(!$this->is_init)
            throw new Exception('Route is not initialized.',-406);
    }

    /**
     * 解析请求路径为路径组
     *
     * - 兼容 `index.php?/app/controller/method` 形式的免 rewrite 写法
     *
     * @access private
     * @param string $uri 请求路径
     * @return array
     */
    private function parseUri(string $uri): array {
        $uri=explode('?',$uri);
        if(count($uri)>1) {
            // 判断第一个元素是否为文件名
            if(preg_match('/(\.\w+|\/)$/',$uri[0]))
                array_shift($uri);
            $uri=implode('?',$uri);
        } else
            $uri=$uri[0];
        // 判断是不是index.php
        if($uri==='/index.php')
            $uri='/';
        $uri=explode('/',$uri);
        foreach($uri as $k=>$v)
            $uri[$k]=preg_replace('/(\?|&).*$/','',$v);
        array_shift($uri);
        return array_values($uri);
    }

}
