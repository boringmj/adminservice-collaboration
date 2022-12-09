<?php

namespace base;

abstract class Exception extends \Exception {

    /**
     * 额外数据
     * @var array
     */
    protected array $data;

    /**
     * 错误码
     * @var int
     */
    protected int $error_code;

    /**
     * 构造方法
     * 
     * @access public
     * @param string $message
     * @param int $error_code
     */
    public function __construct(string $message,int $error_code=0,array $data=array()) {
        $this->error_code=$error_code;
        $this->message=$message;
        $this->data=$data;
    }

    /**
     * 获取额外的数据
     * 
     * @access public
     * @return array
     */
    final public function getData(): array {
        return $this->data;
    }

}

?>