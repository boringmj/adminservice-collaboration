<?php

namespace AdminService;

use bash\Exception as BashException;

final class Exception extends BashException {

    /**
     * 输出错误信息
     * 
     * @access public
     * @return void
     */
    public function echo() {
        echo $this->error_code.':'.$this->getMessage()."<br>\n";
    }

    /**
     * 错误事件触发器
     * @access public
     * @param callable $callback 回调事件
     * @return mixed
     */
    public function trigger(callable $callback) {
        return $callback($this);
    }

    /**
     * 返回错误信息
     * @access public
     * @return array
     */
    public function returnError() {
        return array(
            'error_code'=>$this->error_code,
            'message'=>$this->getMessage()
        );
    }
}

?>