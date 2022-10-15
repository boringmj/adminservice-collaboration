<?php

namespace app\Index\controller;

use bash\Controller;
use bash\File;

class Index extends Controller {

    public function index() {
        return "Hello World!";
    }

    public function test() {
        return "Hi ".$this->param('name','AdminService')."!";
    }

}

?>