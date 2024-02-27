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
        $name=self::getRealClass($name);
        if(!isset(self::$container[$name])) {
            // 如果不存在则判断是否存在该类
            if(!class_exists($name))
                throw new Exception('Class "'.$name.'" not found.');
            // 如果存在则判断是否可以实例化
            $ref=new \ReflectionClass($name);
            if(!$ref->isInstantiable())
                throw new Exception('Class "'.$name.'" is not instantiable.');
            // 如果可以实例化则实例化一个新的对象
            self::$container[$name]=$ref->newInstance();
        }
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
        $name=self::getRealClass($name);
        self::$container[$name]=$object;
    }

    /**
     * 获取未被实例化的类名称
     * 
     * @access public
     * @param string $name 类名
     */
    static public function getClass(string $name): string {
        $name=self::getRealClass($name);
        // 如果类容器中不存在该类则抛出异常
        if(!isset(self::$class_container[$name]))
            throw new Exception('Class "'.$name.'" not found.');
        return self::$class_container[$name];
    }

    /**
     * 获取名称在容器中实际的类名(如果不存在则返回原类名)
     * 
     * @access public
     * @param string $name 类名
     * @return string
     */
    static public function getRealClass(string $name): string {
        if(isset(self::$class_container[$name]))
            return self::$class_container[$name];
        return $name;
    }

    /**
     * 设置或添加未被实例化的类(会覆盖已存在的别名等)
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
     * 通过自动依赖注入实例化一个对象
     * 
     * 注意: 依赖简单支持抽象类和接口,重复依赖可能会抛出找不到对象的异常,
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
            $name=self::getClass($name);
        // 如果不强制实例化且容器中存在该对象则直接返回,如果标识重复也会直接返回
        if((!$is_force&&isset(self::$container[$name])||in_array($name,$flags)))
            return self::get($name);
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
                $class_name=self::getRealClass($type);
                if(class_exists($class_name)) {
                    // 通过反射判断是否可以实例化该类
                    $ref_type=new \ReflectionClass($class_name);
                    if(!$ref_type->isInstantiable()) {
                        // 如果参数类型为抽象类或接口则判断是否可以获取到子类
                        $class_name=self::findSubClass($class_name);
                        if(empty($class_name)||!class_exists($class_name))
                            throw new Exception('Parameter "'.$param->getName().'" of "'.$name.'" constructor is not valid.',0,array(
                                'name'=>$name,
                                'class'=>$class_name,
                                'parameter'=>$param->getName()
                            ));
                    }
                    // 如果参数类型为类则通过自动依赖注入实例化一个新的对象
                    $object=self::make($class_name,false,$flags);
                    $args[]=$object;
                } else {
                    // 其他类型判断是否有默认值,如果有则使用默认值,没有则抛出异常
                    if($param->isDefaultValueAvailable())
                        $args[]=$param->getDefaultValue();
                    else if($param->allowsNull())
                        $args[]=null;
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
        // 将对象添加到容器中
        self::set($name,$object);
        // 移出标识中的当前对象
        array_pop($flags);
        return $object;
    }

    /**
     * 寻找一个类的可实例化的子类(不使用别名)
     * 
     * @access public
     * @param string $class 类名
     * @return ?string
     */
    static public function findSubClass(string $class): ?string {
        // 判断类是否存在
        if(!class_exists($class))
            return null;
        // 获取所有子类
        $sub_classes=get_declared_classes();
        $sub_classes=array_filter($sub_classes,function($sub_class) use ($class) {
            return is_subclass_of($sub_class,$class);
        });
        // 返回第一个子类
        return array_shift($sub_classes);
    }

}

?>