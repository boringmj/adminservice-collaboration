<?php

namespace AdminService;

use base\Exception as BaseException;

final class Exception extends BaseException {

    /**
     * 输出错误信息
     * 
     * @access public
     * @return void
     */
    public function echo(): void {
        echo $this->error_code.':'.$this->getMessage()."<br>\n";
        echo "File: ".$this->getFile()."<br>\n";
        echo "Line: ".$this->getLine()."<br>\n";
        echo "Trace: ".$this->getTraceAsString()."<br>\n";
        print_r($this->getData());
    }

    /**
     * 错误事件触发器
     * 
     * @access public
     * @param callable $callback 回调事件
     * @return mixed
     */
    public function trigger(callable $callback): mixed {
        return $callback($this);
    }

    /**
     * 返回错误信息
     * 
     * @access public
     * @return array
     */
    public function returnError(): array {
        return array(
            'error_code'=>$this->error_code,
            'message'=>$this->getMessage()
        );
    }

}

?>