<?php

namespace AdminService;

use base\Container;
use AdminService\Config;
use AdminService\Exception;
use AdminService\DynamicProxy;

final class App extends Container {

    /**
     * 初始化
     * 
     * @access public
     * @param array $classes 需要初始化的类
     * @return void
     */
    static public function init(array $classes=array()): void {
        // 获取配置文件中的类
        $classes=array_merge($classes,Config::get('app.classes',array()));
        // 遍历类是否存在
        foreach($classes as $class)
            if(!class_exists($class))
                throw new Exception('Class "'.$class.'" not found.');
        parent::$class_container=$classes;
    }

    /**
     * 获取对象
     * 
     * 注意: 依赖不支持抽象类和接口,重复依赖可能会抛出找不到对象的异常,
     * 这种情况请先使用App::set(Class::class,new Class())添加到容器中
     * 
     * @access public
     * @param string $name 对象名
     * @param mixed ...$args 构造函数参数
     * @return object
     */
    static public function get(string $name,...$args): object {
        // 判断是否传入了构造函数参数
        if(count($args)>0) {
            // 判断类是否存在,如果不存在则在容器中寻找
            if(!class_exists($name))
                $name=parent::getClass($name);
            $ref=new \ReflectionClass($name);
            $object=$ref->newInstanceArgs($args);
            // 直接返回对象,不添加到父容器中
            return $object;
        } else {
            // 判断父容器中是否存在该类
            if(isset(parent::$class_container[$name]))
                return parent::get($name);
            // 如果不存在则通过自动依赖注入实例化一个对象
            return parent::make($name);
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
     */
    static public function exec_class_function(object|string $object,string $method,array $args=array()): mixed {
        // 判断是否为类名
        if(is_string($object)) {
            // 如果是类名则通过自动依赖注入实例化一个对象
            $object=self::make($object);
        }
        // 获取方法参数
        $ref=new \ReflectionMethod($object,$method);
        $params=$ref->getParameters();
        $args_temp=self::mergeParams($params,$args);
        // 调用方法
        return $ref->invokeArgs($object,$args_temp);
    }

    /**
     * 执行函数
     * 
     * @access public
     * @param string $function 函数名
     * @param array $args 函数参数(如果为关系型数组,则会将key作为参数名,value作为参数值,如果索引数组,则会逐一赋值,没有赋值的参数会使用默认值)
     * @return mixed
     */
    static public function exec_function(string $function,array $args=array()): mixed {
        // 获取函数参数
        $ref=new \ReflectionFunction($function);
        $params=$ref->getParameters();
        $args_temp=self::mergeParams($params,$args);
        // 调用函数
        return $ref->invokeArgs($args_temp);
    }

    /**
     * 整理和合并参数
     * 
     * @access private
     * @param array $params 参数
     * @param array $args 参数
     * @return array
     */
    static private function mergeParams(array $params,array $args): array {
        $params_temp=array();
        $arg_count=0;
        foreach($params as $param) {
            $type=$param->getType();
            $type=(string)$type;
            $name=$param->getName();
            // 先尝试在参数数组通过参数名查找
            if((isset($args[$name])&&$type==gettype($args[$name])||isset($args[$name])&&$type=='')) {
                $params_temp[]=$args[$name];
                unset($args[$name]);
                continue;
            }
            // 判断是否存在顺位参数
            if(isset($args[$arg_count])&&$type==gettype($args[$arg_count])||isset($args[$arg_count])&&$type=='') {
                $params_temp[]=$args[$arg_count];
                unset($args[$arg_count]);
                // 顺位参数自增
                $arg_count++;
                continue;
            }
            if(class_exists($type)) {
                // 通过反射判断是否可以实例化该类
                $ref_type=new \ReflectionClass($type);
                if($ref_type->isInstantiable()) {
                    // 如果参数类型为类则通过自动依赖注入实例化一个新的对象
                    $params_temp[]=self::make($type);
                } else {
                    // 如果参数类型为抽象类或接口则抛出异常
                    throw new Exception('Parameter "'.$param->getName().'" of "'.$param.'" constructor is not valid.',0,array(
                        'class'=>$param,
                        'parameter'=>$param->getName()
                    ));
                }
            } else {
                // 其他类型判断是否有默认值,如果有则使用默认值,没有则抛出异常
                if($param->isDefaultValueAvailable())
                    $params_temp[]=$param->getDefaultValue();
                else if($param->allowsNull())
                    $params_temp[]=null;
                else
                    throw new Exception('Parameter "'.$param->getName().'" of "'.$param.'" constructor is not valid.',0,array(
                        'class'=>$param,
                        'parameter'=>$param->getName()
                    ));
            }
        }
        return $params_temp;
    }

    /**
     * 生成一个类的代理实例
     * 
     * @access public
     * @param string $name 类名
     * @param array $args 构造函数参数
     * @return DynamicProxy
     */
    static public function proxy(string $name,array $args=array()): DynamicProxy {
        return new DynamicProxy($name,...$args);
    }

    /**
     * 获取当前应用名称
     * 
     * @access public
     * @return string
     */
    static public function getAppName(): ?string {
        self::initRouteInfo();
        if(self::getData('route_info')!==null)
            return self::getData('route_info')['app']??null;
        return null;
    }

    /**
     * 获取当前控制器名称
     */
    static public function getControllerName(): ?string {
        self::initRouteInfo();
        if(self::getData('route_info')!==null)
            return self::getData('route_info')['controller']??null;
        return null;
    }

    /**
     * 获取当前方法名称
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
     */
    static private function initRouteInfo(): void {
        // 检查是否存在缓存
        if(self::getData('route_info')===null) {
            // 获取路由信息
            $route_info=parent::get('Router')->getRouteInfo();
            // 缓存路由信息
            parent::setData('route_info',$route_info);
        }
    }

}

?>