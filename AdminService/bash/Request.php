<?php

namespace bash;

class Request {

    /**
     * 结束运行
     * 
     * @access public
     * @param string $message
     * @return void
     */
    final static function requestExit(string $message='') {
        exit($message);
    }
}

?>