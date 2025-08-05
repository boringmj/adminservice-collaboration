<?php

namespace AdminService;

use \ReflectionException;

/**
 * 动态代理类
 * 
 * @template T of object
 */
class DynamicProxy {

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
     * @param class-string<T> $target 目标类名
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
     * @param array $arguments 参数
     * @return mixed
     * @throws Exception|ReflectionException
     */
    public function __call(string $name,array $arguments) {
        // 判断目标类是否存在该方法
        if(!method_exists($this->__getTarget(),$name))
            throw new Exception('Method "'.$name.'" not found.');
        // 调用目标类的方法
        return App::exec_class_function($this->__getTarget(),$name,$arguments);
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
           // 通过容器类实例化目标类
           $this->__target_object=App::new($this->__getTargetClass(),...$this->__args);
        }
        return $this->__target_object;
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
     * @param string $__target 目标类
     * @return void
     * @throws Exception
     */
    protected function __setTarget(string $__target): void {
        // 判断目标类是否存在
        if(!class_exists($__target))
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