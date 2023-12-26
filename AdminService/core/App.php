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
            return self::make($name);
        }

    }

    /**
     * 通过自动依赖注入实例化一个对象
     * 
     * 注意: 依赖不支持抽象类和接口,重复依赖可能会抛出找不到对象的异常,
     * 这种情况请先使用App::set(Class::class,new Class())添加到容器中
     * 
     * @access public
     * @param string $name 对象名
     * @param bool $is_force 是否强制实例化
     * @param array $falgs 标识(请不要传入该参数,该参数主要用于防止依赖注入死循环)
     * @throws Exception
     * @return object
     */
    static public function make(string $name,bool $is_force=false,array &$flags=array()): object {
        // 判断类是否存在,如果不存在则在容器中寻找
        if(!class_exists($name))
            $name=parent::getClass($name);
        // 如果不强制实例化且父容器中存在该对象则直接返回,如果标识重复也会直接返回
        if((!$is_force&&isset(parent::$container[$name])||in_array($name,$flags)))
            return parent::get($name);
        // 将当前对象添加到标识中
        $flags[]=$name;
        $ref=new \ReflectionClass($name);
        $constructor=$ref->getConstructor();
        $object=null;
        if($constructor!==null) {
            $params=$constructor->getParameters();
            $args=array();
            foreach($params as $param) {
                $type=$param->getType();
                $type=(string)$type;
                // 删除参数类型中的问号
                $type=str_replace('?','',$type);
                if(class_exists($type)) {
                    // 通过反射判断是否可以实例化该类
                    $ref_type=new \ReflectionClass($type);
                    if($ref_type->isInstantiable()) {
                        // 如果参数类型为类则通过自动依赖注入实例化一个新的对象
                        $object=self::make($type,false,$flags);
                        $args[]=$object;
                    } else {
                        // 如果参数类型为抽象类或接口则抛出异常
                        throw new Exception('Parameter "'.$param->getName().'" of "'.$name.'" constructor is not valid.',0,array(
                            'class'=>$name,
                            'parameter'=>$param->getName()
                        ));
                    }
                } else {
                    // 其他类型判断是否有默认值,如果有则使用默认值,没有则抛出异常
                    if($param->isDefaultValueAvailable())
                        $args[]=$param->getDefaultValue();
                    else
                        throw new Exception('Parameter "'.$param->getName().'" of "'.$name.'" constructor is not valid.',0,array(
                            'class'=>$name,
                            'parameter'=>$param->getName()
                        ));
                }
            }
            // 传入构造函数参数实例化一个新的对象
            $object=$ref->newInstanceArgs($args);
        } else
            // 如果没有构造函数则直接实例化一个新的对象
            $object=$ref->newInstance();
        // 将对象添加到父容器中
        parent::set($name,$object);
        // 移出标识中的当前对象
        array_pop($flags);
        return $object;
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
        return new DynamicProxy($name,$args);
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