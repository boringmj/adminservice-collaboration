<?php

namespace app\index\controller;

// 控制器基类
use base\Controller;

// 系统核心类
use AdminService\App;

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
        return json(null,null,$test->test());
    }

    public function log() {
        // 通过 App::get() 传入自定义参数(如果不传入则会尝试自动注入,如果注入失败则会抛出异常)
        App::get("Log",'debug')->write("This is a debug message in {app}.",array(
            'app'=>App::getAppName()
        ));
        // 输出日志文件路径
        return "日志存放目录: ".\AdminService\Config::get('log.path');
    }

}

?>