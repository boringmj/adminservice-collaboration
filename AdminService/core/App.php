<?php

namespace AdminService;

use base\Container;
use \ReflectionClass;
use \ReflectionException;
use \ReflectionFunction;
use \ReflectionMethod;

final class App extends Container {

    /**
     * 初始化
     *
     * @access public
     * @param array $classes 需要初始化的类
     * @return void
     * @throws Exception
     */
    static public function init(array $classes=array()): void {
        // 获取配置文件中的别名
        $classes=array_merge($classes,Config::get('app.alias',array()));
        // 获取配置文件中的类
        $classes=array_merge($classes,Config::get('app.classes',array()));
        // 遍历类是否存在
        foreach($classes as $class)
            if(!class_exists($class))
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
     * @param string $__name 对象名
     * @param mixed ...$args 构造函数参数($args中不允许传入“__name”参数)
     * @return object
     * @throws Exception|ReflectionException
     */
    static public function get(string $__name,...$args): object {
        if(count($args)>0) {
            return self::new($__name,...$args);
        } else {
            // 判断父容器中是否存在该类
            if(isset(parent::$class_container[$__name]))
                return parent::get($__name);
            // 如果不存在则通过自动依赖注入实例化一个对象
            return parent::make($__name);
        }
    }

    /**
     * 实例化一个新对象(不添加到实例容器中)
     * 
     * @access public
     * @param string $__name 对象名
     * @param mixed ...$args 构造函数参数($args中不允许传入“__name”参数)
     * @return object
     * @throws Exception|ReflectionException
     */
    static public function new(string $__name,...$args): object {
        // 判断类是否存在,如果不存在则在容器中寻找
        if(!class_exists($__name))
            $__name=parent::getClass($__name);
        $ref=new ReflectionClass($__name);
        // 获取构造函数的参数
        $constructor=$ref->getConstructor();
        if($constructor===null) {
            // 如果构造函数不存在则直接实例化一个对象
            return $ref->newInstance();
        }
        $params=$constructor->getParameters();
        $args_temp=self::mergeParams($params,$args);
        // 直接返回对象,不添加到父容器中
        return $ref->newInstanceArgs($args_temp);
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
        $ref=new ReflectionMethod($object,$method);
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
     * @throws Exception
     * @throws ReflectionException
     */
    static public function exec_function(string|callable $function,array $args=array()): mixed {
        if(is_string($function)) {
            // 判断是否存在
            if(!function_exists($function))
                throw new Exception('Function "'.$function.'" not found.');
        }
        // 获取函数参数
        $ref=new ReflectionFunction($function);
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
     * @throws Exception
     * @throws ReflectionException
     */
    static private function mergeParams(array $params,array $args): array {
        $params_temp=array();
        $arg_count=0;
        foreach($params as $param) {
            $type=$param->getType();
            $type=(string)$type;
            //  将类型分割为数组
            $types=explode('|',$type);
            $types=self::getStandardTypes($types);
            // 获取参数名
            $name=$param->getName();
            // 判断参数类型是否为可变参数
            if($param->isVariadic()) {
                $numeric_args=array_values(array_filter($args, 'is_numeric',ARRAY_FILTER_USE_KEY));
                $assoc_args=array_filter($args,'is_string',ARRAY_FILTER_USE_KEY);
                $temp_args=array_merge($numeric_args,$assoc_args);
                // 判断是否有类型限制
                if(count($types)===1&&$types[0]==='') {
                    $params_temp=array_merge($params_temp,$temp_args);
                    break;
                }
                // 判断每个参数是否符合类型限制
                foreach($temp_args as $key=>$value) {
                    $type=gettype($value);
                    if(in_array($type,$types)) {
                        if(is_numeric($key))
                            $params_temp[]=$value;
                        else
                            $params_temp[$key]=$value;
                        unset($args[$key]);
                    } else {
                        throw new Exception('Parameter "'.$param->getName().'" of "'.$param.'" is not valid.',0,array(
                            'class'=>$param,
                            'parameter'=>$param->getName(),
                            'error'=>'The parameter type is not valid.'
                        ));
                    }
                }
                break;
            }
            // 先尝试在参数数组通过参数名查找
            if(
                isset($args[$name])&&(
                    in_array(gettype($args[$name]),$types)||
                    $type==''||
                    // 当gettype()返回的类型为object时,获取参数类型是否为类且与type相同
                    gettype($args[$name])=='object'&&class_exists($type)&&$args[$name] instanceof $type
                )
            ) {
                $params_temp[]=$args[$name];
                unset($args[$name]);
                continue;
            }
            // 判断是否存在顺位参数
            if(
                isset($args[$arg_count])&&(
                    in_array(gettype($args[$arg_count]),$types)||
                    $type==''||
                    // 当gettype()返回的类型为object时,获取参数类型是否为类且与type相同
                    gettype($args[$arg_count])=='object'&&class_exists($type)&&$args[$arg_count] instanceof $type
                )
            ) {
                $params_temp[]=$args[$arg_count];
                unset($args[$arg_count]);
                // 顺位参数自增
                $arg_count++;
                continue;
            }
            // 获取第一个可实例化的类
            $real_class=self::getFirstInstantiableClass($types);
            if($real_class!==null) {
                $params_temp[]=self::make($real_class);
                continue;
            }
            elseif($param->isDefaultValueAvailable())
                $params_temp[]=$param->getDefaultValue();
            else if($param->allowsNull())
                $params_temp[]=null;
            else
                throw new Exception('Parameter "'.$param->getName().'" of "'.$param.'" is not valid.',0,array(
                    'class'=>$param,
                    'parameter'=>$param->getName(),
                    'error'=>'The parameter type is not valid or the parameter value is not set.'
                ));
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
     * @throws Exception
     */
    static public function proxy(string $name,array $args=array()): DynamicProxy {
        return new DynamicProxy($name,...$args);
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
            $route_info=parent::get('Route')->getRouteInfo();
            // 缓存路由信息
            parent::setData('route_info',$route_info);
        }
    }

}