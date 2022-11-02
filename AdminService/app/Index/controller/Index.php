<?php

namespace app\index\controller;

use base\Controller;
use app\index\model\Count;
use app\index\model\Sql;

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
        return $this->view('count', array(
            'count'=>$count->add()
        ));
    }

    public function sql() {
        // 预览 Demo, 该方法随时可能被删除
        $this->type('json');
        $test=new Sql();
        return array(
            'data'=>$test->test()
        );
    }

}

?>