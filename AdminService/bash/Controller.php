<?php

namespace bash;

use bash\Request;

/**
 * 控制器基类
 * 
 * @access public
 * @abstract
 * @package bash
 * @version 1.0.0
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
    
}

?>