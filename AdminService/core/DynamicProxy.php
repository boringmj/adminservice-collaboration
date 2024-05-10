<?php

namespace AdminService;

use \ReflectionException;

/**
 * 动态代理类
 */
class DynamicProxy {

    /**
     * 目标类名
     * @var string
     */
    protected string $target;

    /**
     * 目标类对象
     * @var object
     */
    protected object $target_object;

    /**
     * 构造参数
     * @var array
     */
    protected array $args;

    /**
     * 构造函数
     *
     * @access public
     * @param string $target 目标类名
     * @param mixed ...$args 构造函数参数
     * @throws Exception
     */
    public function __construct(string $target,...$args) {
        $this->setTarget($target);
        $this->args=$args;
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
        if(!method_exists($this->getTarget(),$name))
            throw new Exception('Method "'.$name.'" not found.');
        // 调用目标类的方法
        return App::exec_class_function($this->getTarget(),$name,$arguments);
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
        // 判断目标类是否存在该属性
        if(!property_exists($this->getTarget(),$name))
            throw new Exception('Property "'.$name.'" not found.');
        // 返回目标类的属性
        return $this->getTarget()->$name;
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
        // 判断目标类是否存在该属性
        if(!property_exists($this->getTarget(),$name))
            throw new Exception('Property "'.$name.'" not found.');
        // 设置目标类的属性
        $this->getTarget()->$name=$value;
    }

    /**
     * 获取目标类对象
     *
     * @access protected
     * @return object
     * @throws Exception
     * @throws ReflectionException
     */
    protected function getTarget(): object {
        // 如果目标类对象不存在则实例化一个
        if(!isset($this->target_object)) {
           // 通过容器类实例化目标类
           $this->target_object=App::get($this->getTargetClass(),...$this->args);
        }
        return $this->target_object;
    }

    /**
     * 获取目标类
     *
     * @access protected
     * @return string
     * @throws Exception
     */
    protected function getTargetClass(): string {
        // 判断是否已经设置了目标类
        if(!isset($this->target))
            throw new Exception('Target class not found.');
        return $this->target;
    }

    /**
     * 设置目标类
     *
     * @access protected
     * @param string $target 目标类
     * @return void
     * @throws Exception
     */
    protected function setTarget(string $target): void {
        // 判断目标类是否存在
        if(!class_exists($target))
            throw new Exception('Class "'.$target.'" not found.');
        $this->target=$target;
    }

}