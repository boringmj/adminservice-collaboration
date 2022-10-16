<?php

namespace bash;

use AdminService\Request;

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
     * 获取参数
     * 
     * @access public
     * @param int|string $param 参数
     * @param mixed $default 默认值
     * @return mixed
     */
    final public function param(int|string $param,mixed $default=null): mixed {
        $value=Request::get($param);
        return $value==null?$default:$value;
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
        Request::setHeader($name,$value);
    }

    /**
     * 设置返回的数据类型
     * 
     * @access public
     * @param string $type 数据类型(html|json,default:html)
     * @return void
     */
    final public function type(string $type): void {
        Request::setReturnType($type);
    }



}

?>