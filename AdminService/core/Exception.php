<?php

namespace AdminService;

use bash\Exception as BashException;

final class Exception extends BashException {

    public function echo() {
        echo $this->error_code.':'.$this->getMessage();
    }

    public function trigger(callable $callback) {
        $callback($this);
    }

    public function returnError() {
        return array(
            'error_code'=>$this->error_code,
            'message'=>$this->getMessage()
        );
    }
}

?>