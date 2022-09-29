<?php

namespace AdminService;

use bash\Exception as BashException;

class Exception extends BashException {

    public function echo() {
        echo $this->error_code.':'.$this->getMessage();
    }
}

?>