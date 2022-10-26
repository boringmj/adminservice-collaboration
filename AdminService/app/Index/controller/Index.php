<?php

namespace app\index\controller;

use base\Controller;
use app\index\model\Count;
use app\index\model\Test;

class Index extends Controller {

    public function index() {
        $test=new Test();
        return $test->a();
        return "Hello World!";
    }

    public function test() {
        return $this->view(array(
            'name'=>$this->param('name','AdminService')
        ));
    }

    public function count() {
        $count=new Count();
        return $this->view('count', array(
            'count'=>$count->add()
        ));
    }

}

?>