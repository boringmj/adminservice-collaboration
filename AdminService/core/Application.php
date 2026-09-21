<?php

namespace AdminService;

use base\Container as ContainerContract;

use function array_merge;
use function class_exists;
use function interface_exists;
use function is_int;

/**
 * 应用
 *
 * - 引导: 建立**应用级容器**, 按配置装配绑定与开关, 并安装到门面(使用者入口)
 * - 生命周期: 应用级(进程级)容器长期存活; 请求级对象的派生 / 清理由后续步骤接入
 *   (`handle()` 是唯一入口, 请求级 fork/reset 与门面指针切换将落在这里, 常驻模式下每次请求都是一个干净 scope)
 *
 * @access public
 * @package AdminService
 * @version 1.0.0
 */
final class Application {

    /**
     * 应用级容器
     * @var ContainerContract
     */
    private ContainerContract $container;

    /**
     * 构造方法
     *
     * @access public
     * @param ContainerContract|null $container 容器实例
     *  - 未传入时:**复用门面当前已安装的容器**(同一进程内通常只有一个应用级容器),
     *    门面没有实例时才新建 —— 避免"新建一个空应用"把已装配好的容器顶掉
     */
    public function __construct(?ContainerContract $container=null) {
        if($container!==null)
            $this->container=$container;
        elseif(App::hasInstance())
            $this->container=App::getInstance();
        else
            $this->container=new Container();
    }

    /**
     * 初始化(引导)
     *
     * - 按配置装配绑定表与别名: `app.classes`(数值键为"绑定自身", 字符串键为别名)与 `app.alias`
     * - 应用 `app.param_cast` 开关
     * - 安装容器到门面(重复调用不会重建已安装的容器, 语义幂等)
     *
     * @access public
     * @param array<int|string,string> $classes 需要初始化的类
     * @return static
     * @throws Exception
     */
    public function init(array $classes=array()): static {
        // 获取配置文件中的别名(与绑定分表: 别名只描述"名字 → 名字")
        $aliases=Config::get('app.alias',array());
        // 获取配置文件中需要直接绑定到容器中的类(数值键为"绑定自身", 字符串键为"别名 → 类")
        $binds=array();
        $classes=array_merge($classes,Config::get('app.classes',array()));
        foreach($classes as $alias=>$class) {
            if(is_int($alias))
                $binds[$class]=$class;
            else
                $binds[$alias]=$class;
        }
        // 遍历类是否存在
        foreach(array_merge($binds,$aliases) as $class)
            if(!class_exists($class)&&!interface_exists($class))
                throw new Exception('Class "'.$class.'" not found.');
        // 设置是否允许标量参数静默转换(可在 config/app.php 中配置 app.param_cast)
        $this->container->setParamCast(Config::get('app.param_cast',true));
        // 写入绑定表: 自身映射无意义(解析时原样返回), 直接跳过, 其余走 bind(含循环检测)
        foreach($binds as $name=>$class) {
            if($name===$class)
                continue;
            $this->container->bind($name,$class);
        }
        // 写入别名表
        foreach($aliases as $alias=>$abstract) {
            if($alias===$abstract)
                continue;
            $this->container->alias($alias,$abstract);
        }
        // 安装到门面(使用者入口)
        App::setInstance($this->container);
        return $this;
    }

    /**
     * 获取应用级容器
     *
     * @access public
     * @return ContainerContract
     */
    public function container(): ContainerContract {
        return $this->container;
    }

    /**
     * 处理一次请求(应用级入口)
     *
     * - 进入请求作用域: 先把门面指针指向本应用的容器(未 init() 时也能工作)
     * - 请求级 scope 的 fork/reset 将在此接入(每请求一个干净分支, 请求结束丢弃)
     *
     * @access public
     * @param callable $callback 请求处理过程(接收应用级容器)
     * @return mixed
     */
    public function handle(callable $callback): mixed {
        App::setInstance($this->container);
        return $callback($this->container);
    }

}
