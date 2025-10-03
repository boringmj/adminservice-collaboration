<?php

namespace AdminService;

use base\Container;
use \ReflectionException;

final class App extends Container {

    /**
     * 初始化
     *
     * @access public
     * @param array<int|string,string> $classes 需要初始化的类
     * @return void
     * @throws Exception
     */
    static public function init(array $classes=array()): void {
        // 获取配置文件中需要直接绑定到容器中的类
        $binds=array();
        $classes=array_merge($classes,Config::get('app.classes',array()));
        foreach($classes as $alias=>$class) {
            if(is_int($alias))
                $binds[$class]=$class;
            else
                $binds[$alias]=$class;
        }
        // 获取配置文件中的别名
        $aliases=Config::get('app.alias',array());
        // 合并所有类
        $classes=array_merge($binds,$aliases);
        // 遍历类是否存在
        foreach($classes as $class)
            if(!class_exists($class)&&!interface_exists($class))
                throw new Exception('Class "'.$class.'" not found.');
        parent::$class_container=$classes;
    }

    /**
     * 获取对象(传入构造参数则不会添加到实例容器中)
     *
     * 注意: 依赖简单支持抽象类和接口,重复依赖可能会抛出找不到对象的异常,
     * 这种情况请先使用App::set(Class::class,new Class())添加到容器中
     *
     * @access public
     * @template T of object
     * @param class-string<T> $__name 对象名（类名）
     * @param mixed ...$args 构造函数参数($args中不允许传入“__name”参数)
     * @return T|object 返回指定类的实例
     * @throws Exception|ReflectionException
     */
    static public function get(string $__name,...$args): object {
        if(count($args)>0) {
            return self::new($__name,...$args);
        } else {
            // 如果不存在则通过自动依赖注入实例化一个对象
            return parent::make($__name);
        }
    }

    /**
     * 执行类或对象的方法
     *
     * @access public
     * @param object|string $object 对象或者类名
     * @param string $method 方法名
     * @param array $args 方法参数(如果为关系型数组,则会将key作为参数名,value作为参数值,如果索引数组,则会逐一赋值,没有赋值的参数会使用默认值)
     * @return mixed
     * @throws Exception|ReflectionException
     */
    static public function exec_class_function(object|string $object,string $method,array $args=array()): mixed {
        // 判断是否为类名
        if(is_string($object)) {
            // 如果是类名则通过自动依赖注入实例化一个对象
            $object=self::make($object);
        }
        // 获取方法参数
        $ref=self::getReflectionMethodByObject($object,$method);
        $params=$ref->getParameters();
        $args_temp=self::mergeParams($params,$args);
        // 调用方法
        return $ref->invokeArgs($object,$args_temp);
    }

    /**
     * 执行函数
     *
     * @access public
     * @param string|array|callable $function 函数名(支持数组形式的类方法调用和闭包)
     * @param array $args 函数参数(如果为关系型数组,则会将key作为参数名,value作为参数值,如果索引数组,则会逐一赋值,没有赋值的参数会使用默认值)
     * @return mixed
     * @throws Exception|ReflectionException
     */
    static public function exec_function(
        string|array|callable $function,array $args=array()
    ): mixed {
        if(is_array($function)) {
            // 类方法调用
            [$classOrObj,$method]=$function;
            return self::exec_class_function($classOrObj,$method,$args);
        }
        // 获取函数参数
        $ref=self::getReflectionFunction($function);
        $params=$ref->getParameters();
        $args_temp=self::mergeParams($params,$args);
        // 调用函数
        return $ref->invokeArgs($args_temp);
    }

    /**
     * 获取当前应用名称
     *
     * @access public
     * @return string|null
     * @throws Exception
     * @throws ReflectionException
     */
    static public function getAppName(): ?string {
        self::initRouteInfo();
        if(self::getData('route_info')!==null)
            return self::getData('route_info')['app']??null;
        return null;
    }

    /**
     * 获取当前控制器名称
     *
     * @access public
     * @return string|null
     * @throws Exception
     * @throws ReflectionException
     */
    static public function getControllerName(): ?string {
        self::initRouteInfo();
        if(self::getData('route_info')!==null)
            return self::getData('route_info')['controller']??null;
        return null;
    }

    /**
     * 获取当前方法名称
     *
     * @access public
     * @return string|null
     * @throws Exception
     * @throws ReflectionException
     */
    static public function getMethodName(): ?string {
        self::initRouteInfo();
        if(self::getData('route_info')!==null)
            return self::getData('route_info')['method']??null;
        return null;
    }

    /**
     * 初始化路由信息
     *
     * @access private
     * @return void
     * @throws Exception
     * @throws ReflectionException
     */
    static private function initRouteInfo(): void {
        // 检查是否存在缓存
        if(self::getData('route_info')===null) {
            // 获取路由信息
            $route_info=parent::get(Route::class)->getRouteInfo();
            // 缓存路由信息
            parent::setData('route_info',$route_info);
        }
    }

}