<?php

namespace base;

/**
 * 错误处理类
 * 
 * 用于收集和处理PHP错误，包括致命错误和非致命错误。
 */
abstract class Error {

    /**
     * 存储收集到的错误
     * @var array
     */
    protected static $errors=array();

    /**
     * 注册错误处理器
     * 
     * @access public
     * @param callable $normalExitCallback 正常退出时的回调函数
     * @param bool $initialized 标记框架是否初始化完成
     * @return void
     * @abstract
     */
    abstract public static function register(
        ?callable $normalExitCallback=null,bool $initialized=false
    ): void;

    /**
     * 设置框架初始化状态
     * 
     * @access public
     * @param bool $initialized
     * @return void
     * @abstract
     */
    abstract public static function setInitialized(bool $initialized): void;

    /**
     * 获取收集到的错误
     * 
     * @access public
     * @return array
     */
    public static function getErrors(): array {
        return self::$errors;
    }

}