<?php

namespace app\Index\controller;

use bash\Controller;

class Index extends Controller {
    public function index() {
        echo "Hello World!";
    }

    public function test() {
        echo "Hi ".$this->param(0)."!";
    }
}