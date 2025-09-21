<?php

namespace base;

use AdminService\Exception;
use \ReflectionClass;
use \ReflectionException;

abstract class Container {

    /**
     * 对象实例容器
     * @var array
     */
    protected static array $container;

    /**
     * 未被实例化的类容器
     */
    protected static array $class_container;

    /**
     * 全局数据容器
     */
    protected static array $data_container;

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
     * @template T of object
     * @param class-string<T> $__name 对象名（类名）
     * @return T|object 返回指定类的实例
     */
    static public function get(string $name): object {
        $name=self::getRealClass($name);
        if(!isset(self::$container[$name])) {
            // 如果不存在则判断是否存在该类
            if(!class_exists($name))
                throw new Exception('Class "'.$name.'" not found.');
            // 如果存在则判断是否可以实例化
            $ref=new ReflectionClass($name);
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
        // 如果类容器中不存在该类则返回原类名
        if(!isset(self::$class_container[$name]))
            return $name;
        return self::$class_container[$name];
    }

    /**
     * 获取名称在容器中实际的类名(如果不存在则返回原类名)
     * 
     * @access public
     * @param string $name 类名
     * @param bool $recursive 是否递归查找(默认为true)
     * @param bool $max_depth 最大递归深度
     * @return string
     */
    static public function getRealClass(
        string $name,
        bool $recursive=true,
        int $max_depth=255
        ): string {
        if(isset(self::$class_container[$name])) {
            if($max_depth<=0)
                throw new Exception('Maximum recursion depth exceeded while resolving class "'.$name.'"');
            // 判断该类是绑定了其他类
            if($recursive&&isset(self::$class_container[self::$class_container[$name]])) {
                $name=self::getRealClass(self::$class_container[$name],$recursive,$max_depth-1);
            } else
                $name=self::$class_container[$name];
        }
        return $name;
    }

    /**
     * 设置或添加未被实例化的类(会覆盖已存在的别名等)
     * 因为寻找子类时不会逐级查找,所以请确保起始类或结果类被正确绑定
     *
     * @access public
     * @param string $name 别名或父类类名
     * @param string $class 真实类名(需存在)
     * @return void
     * @throws Exception
     */
    static public function setClass(string $name,string $class): void {
        // 如果类不存在则抛出异常
        if(!class_exists($class))
            throw new Exception('Class "'.$class.'" not found.');
        // 判断新绑定是否会形成循环关系
        if(self::isCircular($name,$class))
            throw new Exception('Circular dependency detected for "'.$name.'" and "'.$class.'"');
        self::$class_container[$name]=$class;
    }

    /**
     * 判断绑定是否会形成循环关系
     * 
     * @access public
     * @param string $abstract 别名或父类类名
     * @param string $concrete 目标类名
     * @return bool
     */
    static public function isCircular(string $abstract,string $concrete): bool {
        $visited=[$abstract];
        while(isset(self::$class_container[$concrete])) {
            if(in_array($concrete,$visited,true))
                // 检查到循环
                return true;
            $visited[]=$concrete;
            $concrete=self::$class_container[$concrete];
        }
        // 最终还需要是否自循环
        return $concrete===$abstract;
    }

    /**
     * 为抽象类或接口绑定实现类(会覆盖已存在的绑定或别名)
     * 因为寻找子类时不会逐级查找,所以请确保起始类或结果类被正确绑定
     * 
     * @access public
     * @param string $abstract 别名或父类类名
     * @param string $concrete 真实类名(需存在)
     * @return void
     * @throws Exception
     */
    static public function bind(string $abstract,string $concrete): void {
        self::setClass($abstract,$concrete);
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
     * @throws Exception
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
     * @param array $data 数据数组
     * @return void
     */
    static public function setDataByArray(array $data): void {
        foreach($data as $name=>$value)
            self::setData($name,$value);
    }

    /**
     * 通过自动依赖注入实例化一个对象
     *
     * 注意: 依赖简单支持抽象类和接口,重复依赖可能会抛出找不到对象的异常,
     * 这种情况请先使用App::set(Class::class,new Class())添加到容器中
     *
     * @access public
     * @template T of object
     * @param class-string<T> $name 对象名
     * @param bool $is_force 是否强制实例化(仅对当前对象有效,不会影响依赖)
     * @param array $flags 标识(请不要传入该参数,该参数主要用于防止依赖注入死循环)
     * @return T|object
     * @throws Exception|ReflectionException
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
        $ref=new ReflectionClass($name);
        $constructor=$ref->getConstructor();
        if($constructor!==null) {
            $params=$constructor->getParameters();
            $args=array();
            foreach($params as $param) {
                $type=$param->getType();
                $type=(string)$type;
                // 将类型分割为数组
                $types=explode('|',$type);
                $types=self::getStandardTypes($types);
                // 获取第一个可实例化的类
                $real_class=self::getFirstInstantiableClass($types);
                if($real_class!==null) {
                    // 递归实例化依赖
                    $args[]=self::make($real_class,false,$flags);
                    continue;
                }
                else if($param->isDefaultValueAvailable())
                    $args[]=$param->getDefaultValue();
                else if($param->allowsNull())
                    $args[]=null;
                else
                    throw new Exception('Parameter "'.$param->getName().'" of "'.$name.'" constructor is not valid.',0,array(
                        'class'=>$name,
                        'parameter'=>$param->getName()
                    ));
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
     * 寻找一个类的可实例化的子类(仅结果支持别名和绑定)
     * 
     * @access public
     * @param string $class 类名
     * @return ?string
     */
    static public function findSubClass(string $class): ?string {
        // 判断类是否存在
        if(!class_exists($class)&&!interface_exists($class))
            return null;
        // 获取所有子类
        $sub_classes=get_declared_classes();
        $sub_classes=array_filter($sub_classes,function($sub_class) use ($class) {
            return is_subclass_of($sub_class,$class);
        });
        // 判断是否存在可实例化的子类
        foreach($sub_classes as $sub_class) {
            $sub_class=self::getRealClass($sub_class);
            $ref=new ReflectionClass($sub_class);
            if($ref->isInstantiable())
                return $sub_class;
        }
        // 如果均不可实例化则递归查找,直到找到可实例化的子类
        foreach($sub_classes as $sub_class) {
            $sub_class=self::findSubClass($sub_class);
            if($sub_class!==null)
                return $sub_class;
        }
        return null;
    }

    /**
     * 将一个PHP类型转为gettype()返回的类型
     * 
     * @access public
     * @param string $type 类型
     * @return string
     */
    static public function getStandardType(string $type): string {
        // 清除类型前缀
        $type=str_replace('?','',$type);
        $list=array(
            'int'=>'integer',
            'bool'=>'boolean',
            'float'=>'double',
            'null'=>'NULL'
        );
        if(isset($list[$type]))
            return $list[$type];
        return $type;
    }

    /**
     * 将一组PHP类型转为gettype()返回的类型
     * 
     * @access public
     * @param array $types 类型数组
     * @return array
     */
    static public function getStandardTypes(array $types): array {
        $result=array();
        foreach($types as $type)
            $result[]=self::getStandardType($type);
        return $result;
    }

    /**
     * 获取给出类型中的第一个可实例化的类(支持别名和绑定)
     * 
     * @access public
     * @param array $types 类型数组
     * @return ?string
     */
    static public function getFirstInstantiableClass(array $types): ?string {
        foreach($types as $type) {
            // 判断是否可以实例化该类
            $class_name=self::getRealClass($type);
            if(class_exists($class_name)) {
                // 通过反射判断是否可以实例化该类
                $ref_type=new ReflectionClass($class_name);
                if(!$ref_type->isInstantiable()) {
                    // 如果不可以实例化则尝试寻找一个可实例化的子类
                    $class_name=self::findSubClass($class_name);
                    if($class_name!==null)
                        return $class_name;
                    else
                        continue;
                }
                return $class_name;
            }
            // 处理接口
            if(interface_exists($class_name)) {
                // 尝试寻找可实例化的实现类
                $class_name=self::findSubClass($class_name);
                if($class_name!==null)
                    return $class_name;
                continue;
            }
        }
        return null;
    }

}