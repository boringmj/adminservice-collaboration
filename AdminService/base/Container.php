<?php

namespace base;

use AdminService\Exception;

abstract class Container {

    /**
     * 对象实例容器
     * @var array
     */
    protected static $container;

    /**
     * 未被实例化的类容器
     */
    protected static $class_container;

    /**
     * 全局数据容器
     */
    protected static $data_container;

    /**
     * 初始化
     * 
     * @access public
     * @param array $classes 需要初始化的类
     * @return void
     */
    abstract static public function init(array $classes=array()): void;

    /**
     * 获取对象(如果不存在则自动实例化,自动实例化的前提是构造函数不含任何参数且在类容器中存在)
     * 
     * @access public
     * @param string $name 对象名
     * @return object
     */
    static public function get(string $name): object {
        if(!isset(self::$container[$name]))
            if(isset(self::$class_container[$name])) {
                $class=self::$class_container[$name];
                self::$container[$name]=new $class();
            } else
                throw new Exception('Object "'.$name.'" not found.');
        return self::$container[$name];
    }

    /**
     * 设置或添加对象
     * 
     * @access public
     * @param string $name 对象名
     * @param object $object 对象
     * @return void
     */
    static public function set(string $name,object $object): void {
        self::$container[$name]=$object;
    }

    /**
     * 获取未被实例化的类名称
     * 
     * @access public
     * @param string $name 类名
     */
    static public function getClass(string $name): string {
        // 如果类容器中不存在该类则抛出异常
        if(!isset(self::$class_container[$name]))
            throw new Exception('Class "'.$name.'" not found.');
        return self::$class_container[$name];
    }

    /**
     * 设置或添加未被实例化的类
     * 
     * @access public
     * @param string $name 类名
     * @param string $class 类
     * @return void
     */
    static public function setClass(string $name,string $class): void {
        // 如果类不存在则抛出异常
        if(!class_exists($class))
            throw new Exception('Class "'.$name.'" not found.');
        self::$class_container[$name]=$class;
    }

    /**
     * 批量设置或添加对象
     * 
     * @access public
     * @param array $objects 对象数组
     * @return void
     */
    static public function setByArray(array $objects): void {
        foreach($objects as $name=>$object)
            self::set($name,$object);
    }

    /**
     * 批量设置或添加未被实例化的类
     * 
     * @access public
     * @param array $classes 类数组
     * @return void
     */
    static public function setClassByArray(array $classes): void {
        foreach($classes as $name=>$class)
            self::setClass($name,$class);
    }

    /**
     * 获取全局数据
     * 
     * @access public
     * @param string $name 数据名
     * @param mixed $default 默认值
     * @return mixed
     */
    static public function getData(string $name,mixed $default=null): mixed {
        return self::$data_container[$name]??$default;
    }

    /**
     * 设置或添加全局数据
     * 
     * @access public
     * @param string $name 数据名
     * @param mixed $data 数据
     * @return void
     */
    static public function setData(string $name,mixed $data): void {
        self::$data_container[$name]=$data;
    }

    /**
     * 批量设置或添加全局数据
     * 
     * @access public
     * @param array $datas 数据数组
     * @return void
     */
    static public function setDataByArray(array $datas): void {
        foreach($datas as $name=>$data)
            self::setData($name,$data);
    }

    /**
     * 自动化依赖注入(本方法不会对构造函自动注入,所以需要传入对象),请注意,系统类不支持自动注入,
     * 需要注入的类不支持需要传参的构造函数
     * 
     * @access public
     * @param object $object 对象
     * @param string $method 方法名
     * @return mixed
     */
    static public function autoInject(object $object,string $method): mixed {
        // 获取方法参数
        $reflection=new \ReflectionMethod($object,$method);
        $params=$reflection->getParameters();
        // 如果方法参数为空则直接执行方法
        if(empty($params))
            return $object->$method();
        // 依赖注入
        $args=[];
        foreach($params as $param) {
            // 获取参数类型
            $type=$param->getType();
            if(is_object($type)&&class_exists($type)) {
                // 如果参数类型为类则实例化该类
                $type=(string)$type;
                $args[]=new $type;
            } else {
                // 判断是否有默认值
                if($param->isDefaultValueAvailable())
                    $args[]=$param->getDefaultValue();
                if($param->allowsNull())
                    $args[]=null;
                else
                    throw new Exception('Unsupported form of dependency injection.');
            }
        }
        // 执行方法
        return $reflection->invokeArgs($object,$args);
    }

}

?>