<?php

namespace app\index\controller;

use base\Controller;
use app\index\model\Count;

class Index extends Controller {

    public function index() {
        return "Hello World!";
    }

    public function test() {
        return $this->view(array(
            'name'=>$this->param('name','AdminService')
        ));
    }

    public function count() {
        $count=new Count();
        return "Count: ".$count->add();
    }

}

?>