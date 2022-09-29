<?php

namespace app\Index\controller;

use bash\Request;

class Index {
    public function index() {
        echo "Hello World!";
    }

    public function test() {
        echo "Hi ".Request::get(0)."!";
    }
}