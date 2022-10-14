<?php

namespace app\Index\controller;

use bash\Controller;
use bash\Request;

class Index extends Controller {
    public function index() {
        print_r(Request::$request_params);
        Request::params('name','default');
        print_r(Request::params('name'));
        return "Hello World!";
    }

    public function test() {
        return "Hi ".$this->param('name','AdminService')."!";
    }
}

?>