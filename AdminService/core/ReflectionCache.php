<?php

namespace AdminService;

use ReflectionClass;
use ReflectionMethod;
use ReflectionFunction;
use ReflectionException;
use Closure;

use function explode;
use function str_contains;

/**
 * 反射缓存
 *
 * - 容器内核的反射部分(类 / 方法 / 函数三类缓存)
 * - 缓存是**实例状态**: 每个容器实例一份, 不再有全局静态缓存
 * - 只服务 `core/` 内部(容器、参数解析器、装配器), 因此不抽契约
 *
 * @access public
 * @package AdminService
 * @version 1.0.0
 */
final class ReflectionCache {

    /**
     * 缓存的反射类解析对象
     * @var array<string,ReflectionClass>
     */
    private array $classes=array();

    /**
     * 缓存的反射方法对象
     * @var array<string,ReflectionMethod>
     */
    private array $methods=array();

    /**
     * 缓存的反射函数对象
     * @var array<string,ReflectionFunction>
     */
    private array $functions=array();

    /**
     * "可实例化子类"查找结果缓存: 键 => 找到的实现类名
     *
     * - **只缓存"找到了"的结果, 否定结果永不入缓存**: 查找是在**已声明类**里扫的, "此刻没找到"随时可能
     *   因为某个子类刚被加载而变成"找得到", 缓存否定结果就会把临时答案固化成错的;
     *   而"找到一个可实例化的实现类"这个结论在进程内**稳定不变**, 可以放心缓存。
     * - 只缓存**顶层**查询(见 `ClassFinder::findDirectSubClassRecursive`): 递归内部的中间结果
     *   依赖"防环标识", 缓存它们会把一次带环的探索结果固化下来
     *
     * @var array<string,string>
     */
    private array $sub_classes=array();

    /**
     * 取"可实例化子类"的缓存结果(null = 没有缓存)
     *
     * @access public
     * @param string $name 已解析的类名
     * @return string|null
     */
    public function getSubClass(string $name): ?string {
        return $this->sub_classes[$name]??null;
    }

    /**
     * 写入"可实例化子类"的查找结果(**只写找到了的**)
     *
     * @access public
     * @param string $name 已解析的类名
     * @param string $value 找到的可实例化实现类名
     * @return void
     */
    public function setSubClass(string $name,string $value): void {
        $this->sub_classes[$name]=$value;
    }

    /**
     * 获取反射类对象(会缓存结果)
     *
     * @access public
     * @param string $name 类名
     * @return ReflectionClass
     * @throws ReflectionException
     */
    public function getClass(string $name): ReflectionClass {
        if(!isset($this->classes[$name]))
            $this->classes[$name]=new ReflectionClass($name);
        return $this->classes[$name];
    }

    /**
     * 通过已有对象获取反射类对象(会缓存结果)
     *
     * @access public
     * @param object $object 对象
     * @return ReflectionClass
     */
    public function getObject(object $object): ReflectionClass {
        return $this->getClass($object::class);
    }

    /**
     * 获取反射方法对象(会缓存结果, 支持 `Class::method` 语法)
     *
     * @access public
     * @param string $class 类名或 `类名::方法名`
     * @param string|null $method 方法名
     * @return ReflectionMethod
     * @throws ReflectionException
     */
    public function getMethod(string $class,?string $method=null): ReflectionMethod {
        // 如果使用 Class::method 语法
        if($method===null&&str_contains($class,'::'))
            [$class,$method]=explode('::',$class,2);
        if(!$method)
            throw new ReflectionException(
                "Method name not provided for class '{$class}'"
            );
        $key=$class.'::'.$method;
        if(!isset($this->methods[$key]))
            $this->methods[$key]=new ReflectionMethod($class,$method);
        return $this->methods[$key];
    }

    /**
     * 通过已有对象获取反射方法对象(会缓存结果)
     *
     * @access public
     * @param object $object 对象
     * @param string $method 方法名
     * @return ReflectionMethod
     * @throws ReflectionException
     */
    public function getMethodByObject(object $object,string $method): ReflectionMethod {
        return $this->getMethod($object::class,$method);
    }

    /**
     * 获取反射函数对象(函数名会缓存; 闭包**不缓存**)
     *
     * - 闭包以对象 id 缓存不安全(id 会被复用), 而 `new ReflectionFunction($closure)` 本身很便宜
     *
     * @access public
     * @param string|Closure $function 函数名或闭包
     * @return ReflectionFunction
     * @throws ReflectionException
     */
    public function getFunction(string|Closure $function): ReflectionFunction {
        if($function instanceof Closure)
            return new ReflectionFunction($function);
        if(!isset($this->functions[$function]))
            $this->functions[$function]=new ReflectionFunction($function);
        return $this->functions[$function];
    }

    /**
     * 清空缓存
     *
     * - 常驻模式下请求级容器被丢弃时同步释放(避免跨请求长期持有反射对象)
     *
     * @access public
     * @return void
     */
    public function clear(): void {
        $this->classes=array();
        $this->methods=array();
        $this->functions=array();
    }

}
