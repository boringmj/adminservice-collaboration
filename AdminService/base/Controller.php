<?php

namespace base;

use base\Request;

/**
 * 控制器基类
 * 
 * @access public
 * @abstract
 * @package bash
 * @version 1.0.1
 */
abstract class Controller {

    /**
     * 请求对象
     */
    private Request $request;

    /**
     * 构造方法
     * 
     * @access public
     * @param Request $request 请求对象
     * @return void
     */
    final public function __construct(Request $request) {
        $this->request=$request;
    }

    /**
     * 获取参数
     * 
     * @access public
     * @param int|string $param 参数
     * @param mixed $default 默认值
     * @return mixed
     */
    final public function param(int|string $param,mixed $default=null): mixed {
        return $this->request::get($param,$default);
    }

    /**
     * 设置Header
     * 
     * @access public
     * @param string $name 名称
     * @param string $value 值
     * @return void
     */
    final public function header(string $name,string $value): void {
        $this->request::setHeader($name,$value);
    }

    /**
     * 设置返回的数据类型
     * 
     * @access public
     * @param string $type 数据类型(html|json,default:html)
     * @return void
     */
    final public function type(string $type): void {
        $this->request::setReturnType($type);
    }



}

?>