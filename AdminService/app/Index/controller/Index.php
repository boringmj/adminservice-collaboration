<?php

namespace app\Index\controller;

use bash\Controller;
use bash\Request;

class Index extends Controller {
    public function index() {
        return "Hello World!";
    }

    public function test() {
        return "Hi ".$this->param(0)."!";
    }
}

?>