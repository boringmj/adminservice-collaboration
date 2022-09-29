<?php

namespace bash;

abstract class Exception extends \Exception {

    /**
     * 额外数据
     */
    private $data;

    /**
     * 构造方法
     * 
     * @access public
     * @param string $message
     * @param int $error_code
     * @return Exception
     */
    final public function __construct(string $message,int $error_code=0,array $data=array()) {
        $this->error_code=$error_code;
        $this->message=$message;
        $this->data=$data;
        return $this;
    }

    /**
     * 获取额外的数据
     * 
     * @access public
     * @return array
     */
    final public function getData() {
        return $this->data;
    }
}

?>