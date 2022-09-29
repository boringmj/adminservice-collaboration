<?php

namespace bash;

abstract class Exception extends \Exception {

    private $error_retrun_code;

    /**
     * 构造方法
     * 
     * @access public
     * @param string $message
     * @param int $error_code
     * @return Exception
     */
    final public function __construct(string $message,int $error_code=0) {
        $this->error_code=$error_code;
        $this->message=$message;
        return $this;
    }
}

?>