<?php

namespace AdminService;

use base\Container as ContainerContract;
use ReflectionClass;
use ReflectionException;

/**
 * 动态代理类
 * 
 * @template T of object
 * @mixin T
 */
class DynamicProxy {

    /**
     * 容器契约(由创建代理的一方注入)
     * @var ContainerContract|null
     */
    protected ?ContainerContract $container=null;

    /**
     * 目标类名
     * @var string
     */
    protected string $__target;

    /**
     * 目标类对象
     * @var T
     */
    protected object $__target_object;

    /**
     * 构造参数
     * @var array
     */
    protected array $__args;

    /**
     * 构造函数
     *
     * @access public
     * @param class-string<T> $target 目标类名或接口名
     * @param mixed ...$args 构造函数参数
     * @throws Exception
     */
    public function __construct(string $target,...$args) {
        $this->__setTarget($target);
        $this->__args=$args;
    }

    /**
     * 调用目标类的方法
     *
     * @access public
     * @param string $name 方法名
     * @param array<mixed> $arguments 参数
     * @return mixed
     * @throws Exception|ReflectionException
     */
    public function __call(string $name,array $arguments) {
        // 判断目标类是否存在该方法
        if(!method_exists($this->__getTarget(),$name))
            throw new Exception('Method "'.$name.'" not found.');
        // 调用目标类的方法
        return $this->container()->exec_class_function($this->__getTarget(),$name,$arguments);
    }

    /**
     * 调用目标成员属性
     *
     * @access public
     * @param string $name 属性名
     * @return mixed
     * @throws Exception|ReflectionException
     */
    public function __get(string $name) {
        /**
         * 在实际的使用中,并不需要检查属性是否存在,所以注释掉了这段代码
         * 如果你需要检查属性是否存在,请取消注释
         * 如果对应的属性不存在,则返回null
         */
        // 判断目标类是否存在该属性
        // if(!property_exists($this->__get__Target(),$name))
        //     throw new Exception('Property "'.$name.'" not found.');
        if(!property_exists($this->__getTarget(),$name))
            return null;
        // 返回目标类的属性
        return $this->__getTarget()->$name;
    }

    /**
     * 设置目标成员属性
     *
     * @access public
     * @param string $name 属性名
     * @param mixed $value 属性值
     * @return void
     * @throws Exception|ReflectionException
     */
    public function __set(string $name,mixed $value): void {
        /**
         * 在实际的使用中,并不需要检查属性是否存在,所以注释掉了这段代码
         * 如果你需要检查属性是否存在,请取消注释
         */
        // // 判断目标类是否存在该属性
        // if(!property_exists($this->__getTarget(),$name))
        //     throw new Exception('Property "'.$name.'" not found.');
        // 设置目标类的属性
        $this->__getTarget()->$name=$value;
    }

    /**
     * 获取目标类对象
     *
     * @access protected
     * @return T
     * @throws Exception
     * @throws ReflectionException
     */
    protected function __getTarget(): object {
        // 如果目标类对象不存在则实例化一个
        if(!isset($this->__target_object)) {
           // 通过容器构建目标类(带构造参数)
           $this->__target_object=$this->container()->build($this->__getTargetClass(),...$this->__args);
        }
        return $this->__target_object;
    }

    /**
     * 获取容器(由创建代理的一方注入)
     *
     * - 代理不再反向依赖静态门面;未注入容器时给出明确异常
     *
     * @access protected
     * @return ContainerContract
     * @throws Exception
     */
    protected function container(): ContainerContract {
        if($this->container===null)
            throw new Exception('代理未绑定容器: 请由容器构建被注入的代理对象(如 #[AutowireProperty(类名,proxy:true)])');
        return $this->container;
    }

    /**
     * 绑定容器
     *
     * @access public
     * @param ContainerContract $container 容器契约
     * @return void
     */
    public function setContainer(ContainerContract $container): void {
        $this->container=$container;
    }

    /**
     * 获取目标类
     *
     * @access protected
     * @return string
     * @throws Exception
     */
    protected function __getTargetClass(): string {
        // 判断是否已经设置了目标类
        if(!isset($this->__target))
            throw new Exception('__Target class not found.');
        return $this->__target;
    }

    /**
     * 设置目标类
     *
     * @access protected
     * @param class-string<T> $__target 目标类
     * @return void
     * @throws Exception
     */
    protected function __setTarget(string $__target): void {
        // 判断目标"类或接口"是否存在: 取反射一步即可判定(取不到就抛), 不必先 class_exists / interface_exists
        // 再取一次反射 —— 那是把同一件事做两遍
        // trait 要挡掉: 它同样能取到反射, 但没法被代理(旧写法也是拒绝的, 这里保持行为一致)
        try {
            $ref=new ReflectionClass($__target);
        } catch(ReflectionException) {
            throw new Exception('Class "'.$__target.'" not found.');
        }
        if($ref->isTrait())
            throw new Exception('Class "'.$__target.'" not found.');
        $this->__target=$__target;
    }

    /**
     * 获取目标类实例(主要是让ide能识别代理方法,但实际情况并不严谨,所以默认回返真实对象)
     *
     * @access public
     * @param bool $return_proxy 是否返回代理对象 
     * @return T
     */
    public function instance(bool $return_proxy=false): object {
        if($return_proxy)
            return $this;
        return $this->__getTarget();
    }

}