<?php

namespace app\index\controller;

// 控制器基类
use base\Controller;

// 模型
use app\index\model\Count;
use app\index\model\Sql;

// 控制器助手函数
use function AdminService\common\view;
use function AdminService\common\json;

class Demo extends Controller {

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
        return view('count', array(
            'count'=>$count->add()
        ));
    }

    public function sql() {
        // 预览 Demo, 该方法随时可能被删除
        $test=new Sql();
        return json(array(
            'data'=>$test->test()
        ));
    }

}

?>